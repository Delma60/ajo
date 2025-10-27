<?php

namespace App\Jobs;

use App\Classes\GroupPayoutService;
use App\Models\Group;
use App\Models\PendingAccountBalance;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessDueGroupCycles implements ShouldQueue
{
    use Queueable;

    private $groupId;
    public $tries = 3;

    public function __construct($groupId)
    {
        $this->groupId = $groupId;
    }

    public function handle(GroupPayoutService $groupPayoutService): void
    {
        $lockKey = "process_group_cycle_{$this->groupId}";
        $lockTtl = 300;
        $lock = Cache::lock($lockKey, $lockTtl);

        // Acquire lock; bail out if cannot
        if (!$lock->get()) {
            Log::info("[ProcessDueGroupCycles] Lock not acquired for group {$this->groupId}.");
            return;
        }

        try {
            DB::beginTransaction();

            // Load group with users and cycles
            $group = Group::with(['cycles', 'users'])->find($this->groupId);

            if (!$group) {
                Log::warning("[ProcessDueGroupCycles] Group {$this->groupId} not found.");
                DB::rollBack();
                return;
            }
            if ($group->status !== 'active') {
                Log::info("[ProcessDueGroupCycles] Group {$this->groupId} is not active. Skipping.");
                DB::rollBack();
                return;
            }

            $now = Carbon::now();
            $nextPayout = $group->next_payout ?? $now;
            $periodEnd = Carbon::parse($nextPayout)->endOfDay();
            $periodStart = $this->subtractInterval(Carbon::parse($nextPayout), $group->frequency)->startOfDay();

            // recipients to receive this cycle (service may return models/resources)
            $recipients = $groupPayoutService->determineRecipient($group);

            if (empty($recipients) || $recipients->isEmpty()) {
                Log::info("[ProcessDueGroupCycles] No recipients for group {$group->id}.");
                DB::rollBack();
                return;
            }

            // Snapshot saved BEFORE mutating
            $totalSaved = (float) $group->saved;
            if ($totalSaved <= 0) {
                Log::warning("[ProcessDueGroupCycles] Group {$group->id} has zero saved. Skipping payout.");
                DB::rollBack();
                return;
            }

            // members count and canonical contribution amount (for fee calc)
            $membersCount = max(1, $group->users()->count());
            if (!empty($group->meta['contribution'])) {
                $contributionPerMember = (float) $group->meta['contribution'];
            } elseif ($membersCount > 0 && $group->goal !== null) {
                $contributionPerMember = round($group->goal / $membersCount, 2);
            } else {
                $contributionPerMember = $membersCount ? round($totalSaved / $membersCount, 2) : 0.0;
            }

            // --- Defaulter fee assessment (applies to ALL group members who didn't pay this period) ---
            $defaultFeeRate = 10.0; // percent
            $periodIdentifier = $periodEnd->toDateString(); // canonical id for this payout
            $feeTimestamp = $periodEnd->toDateTimeString();

            foreach ($group->users as $memberModel) {
                // check pivot.last_payment_at for this member
                $lastPaymentAt = $memberModel->pivot->last_payment_at ?? null;
                $paidThisPeriod = false;
                if (!empty($lastPaymentAt)) {
                    try {
                        $lp = Carbon::parse($lastPaymentAt);
                        $paidThisPeriod = $lp->between($periodStart, $periodEnd);
                    } catch (Throwable $e) {
                        $paidThisPeriod = false;
                    }
                }

                if ($paidThisPeriod) {
                    // contributor already paid for this period
                    continue;
                }

                // idempotency check: don't re-assess if fee_assessed_at already set for this period
                $pivotRow = DB::table('group_user')
                    ->where('group_id', $group->id)
                    ->where('user_id', $memberModel->id)
                    ->first(['outstanding_debit', 'cycles_missed', 'fee_assessed_at']);

                $alreadyAssessed = false;
                if (!empty($pivotRow) && !empty($pivotRow->fee_assessed_at)) {
                    $alreadyAssessed = Carbon::parse($pivotRow->fee_assessed_at)->toDateString() === $periodIdentifier;
                }
                if ($alreadyAssessed) {
                    continue;
                }

                // compute fee amount (10% of contributionPerMember)
                $feeAmount = round($contributionPerMember * ($defaultFeeRate / 100), 2);
                if ($feeAmount <= 0) {
                    continue;
                }

                // atomically add fee to pivot outstanding_debit and increment cycles_missed
                DB::table('group_user')
                    ->where('group_id', $group->id)
                    ->where('user_id', $memberModel->id)
                    ->update([
                        'outstanding_debit' => DB::raw("COALESCE(outstanding_debit, 0) + {$feeAmount}"),
                        'cycles_missed' => DB::raw("COALESCE(cycles_missed, 0) + 1"),
                        'fee_assessed_at' => $feeTimestamp,
                    ]);

                Log::info("[ProcessDueGroupCycles] Assessed fee {$feeAmount} to user {$memberModel->id} for group {$group->id}.");
            }

            // --- Allocate saved across recipients ---
            $recipientCount = $recipients->count();
            $perRecipient = $recipientCount > 0 ? round($totalSaved / $recipientCount, 2) : 0.0;
            $allocated = round($perRecipient * $recipientCount, 2);
            $remainder = round($totalSaved - $allocated, 2);

            // use DB to get accurate existing cycles count
            $existingCycleCount = $group->cycles()->count();

            $index = 0;
            foreach ($recipients as $recipient) {
                $index++;
                $userId = data_get($recipient, 'id') ?: $recipient->id ?? null;
                if (!$userId) {
                    Log::warning("[ProcessDueGroupCycles] Recipient without id in group {$group->id}. Skipping.");
                    continue;
                }

                $cycleNumber = $existingCycleCount + $index;

                // compute payout amount (first recipient receives remainder)
                $payoutAmount = $perRecipient;
                if ($index === 1) {
                    $payoutAmount = round($perRecipient + $remainder, 2);
                }

                // create cycle entry
                $group->cycles()->create([
                    'cycle_number' => $cycleNumber,
                    'recipient' => $userId,
                    'start_at' => $periodStart,
                    'end_at' => $periodEnd,
                    'amount' => $payoutAmount,
                ]);

                // idempotency for Transaction: skip if same idempotency_key exists
                $idempotencyKey = "payout_group_{$group->id}_user_{$userId}_cycle_{$cycleNumber}";
                $existsTx = Transaction::where('idempotency_key', $idempotencyKey)->exists();
                if ($existsTx) {
                    Log::info("[ProcessDueGroupCycles] Transaction already exists for {$idempotencyKey}, skipping creation.");
                } else {
                    Transaction::create([
                        'uuid' => (string) Str::uuid(),
                        'reference' => "payout:group:{$group->id}:user:{$userId}:cycle:{$cycleNumber}",
                        'idempotency_key' => $idempotencyKey,
                        'group_id' => $group->id,
                        'user_id' => $userId,
                        'amount' => $payoutAmount,
                        'fee' => 0,
                        'net_amount' => $payoutAmount,
                        'status' => 'success',
                        'type' => Transaction::TYPE_PAYOUT,
                        'direction' => Transaction::DIRECTION_CREDIT,
                        'method' => 'internal_payout',
                        'provider_reference' => 'internal_payout',
                        'meta' => ['note' => 'payout'],
                    ]);
                }

                // pending balance creation is idempotent-ish: create a pending record per payout occurrence.
                PendingAccountBalance::create([
                    'user_id' => $userId,
                    'amount' => $payoutAmount,
                ]);

                Log::info("[ProcessDueGroupCycles] Allocated payout {$payoutAmount} to user {$userId} for group {$group->id} (cycle {$cycleNumber}).");
            }

            // Zero group's saved AFTER allocation
            $group->update(['saved' => 0]);

            DB::commit();

            Log::info("[ProcessDueGroupCycles] Processed group {$group->id}: distributed {$totalSaved} to {$recipientCount} recipient(s).");

        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("[ProcessDueGroupCycles] Error processing group {$this->groupId}: " . $e->getMessage(), [
                'exception' => $e,
                'group_id' => $this->groupId,
            ]);
        } finally {
            // always release the lock
            try {
                $lock->release();
            } catch (Throwable $releaseException) {
                Log::warning("[ProcessDueGroupCycles] Failed to release lock for group {$this->groupId}: " . $releaseException->getMessage());
            }
        }
    }

    private function subtractInterval(Carbon $date, string $frequency): Carbon
    {
        switch (strtolower($frequency)) {
            case 'daily':
                return $date->copy()->subDay();
            case 'weekly':
                return $date->copy()->subWeek();
            case 'bi-weekly':
            case 'biweekly':
            case 'bi_weekly':
                return $date->copy()->subWeeks(2);
            case 'monthly':
            default:
                return $date->copy()->subMonth();
        }
    }
}
