<?php

namespace App\Services;

use App\Classes\Payment\PaymentFactory;
use App\Models\Group;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserBankCard;
use App\Models\VirtualBank;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Notifications\TransactionNotification;

class PaymentService
{
    /**
     * Pay a group contribution for $user.
     *
     * If $useWallet === true, the payment is immediate (wallet withdrawal).
     * For external providers, a pending Transaction is created (and returned).
     *
     * @param User $user
     * @param Group $group
     * @param float $amount
     * @param string|null $provider
     * @param string|null $reference
     * @param bool $useWallet
     * @return Transaction
     * @throws \Throwable
     */
    public function payContribution(User $user, Group $group, float $amount, ?string $provider = null, ?string $reference = null, bool $useWallet = false)
    {
        $provider = $provider ?? 'system';
        $amount = (float) $amount;
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        // Prevent duplicate payments for the current period (best-effort)
        try {
            $period = $group->currentPeriod(); // expected ['start' => Carbon, 'end' => Carbon]
            $existing = Transaction::where('user_id', $user->id)
                ->where('group_id', $group->id)
                ->where('type', Transaction::TYPE_CHARGE)
                ->where('status', Transaction::STATUS_SUCCESS)
                ->whereBetween('created_at', [$period['start'], $period['end']])
                ->exists();

            if ($existing) {
                throw new \RuntimeException('You have already paid for this period.');
            }
        } catch (\Throwable $e) {
            // fail-safe: log and continue (if currentPeriod() fails we don't block payments)
            Log::warning('Could not determine payment period or duplicate check failed: ' . $e->getMessage());
        }

        // Build metadata and deterministic idempotency key for this logical operation
        $meta = [
            'group_id' => $group->id,
            'note' => 'group contribution',
            'reference' => $reference,
        ];
        $idempotency = $meta['idempotency_key'] ?? "contribution_g{$group->id}_u{$user->id}_" . md5($amount . '|' . ($reference ?? ''));

        // If using wallet, withdraw immediately and record TX as success (atomic)
        if ($useWallet) {
            return DB::transaction(function () use ($user, $group, $amount, $meta, $idempotency) {
                // withdrawFromWallet will throw if insufficient balance and will create a SUCCESS transaction
                $tx = $user->withdrawFromWallet($amount, array_merge($meta, ['idempotency_key' => $idempotency]));

                // Safely update pivot contributed and group's saved in a DB-atomic way
                try {
                    // increment pivot contributed and total_contributed, set last_payment_at
                    $userPivotExists = $group->users()->where('user_id', $user->id)->exists();

                    if ($userPivotExists) {
                        // updateExistingPivot with DB::raw to avoid race conditions
                        $group->users()->updateExistingPivot($user->id, [
                            'contributed' => DB::raw("COALESCE(contributed,0) + {$amount}"),
                            'total_contributed' => DB::raw("COALESCE(total_contributed,0) + {$amount}"),
                            'last_payment_at' => now(),
                        ]);
                    } else {
                        // attach user with initial values
                        $group->users()->attach($user->id, [
                            'contributed' => $amount,
                            'total_contributed' => $amount,
                            'last_payment_at' => now(),
                            // 'role' => 'member',
                        ]);
                    }

                    // increment group saved
                    $group->increment('saved', $amount);
                } catch (\Throwable $e) {
                    // If pivot update fails, we should surface the error (but wallet already debited).
                    // Log and rethrow to let caller decide; advanced handling: refund or create compensating txn.
                    Log::error("Failed to update group pivot/saved after wallet withdrawal: " . $e->getMessage(), ['group' => $group->id, 'user' => $user->id]);
                    throw $e;
                }

                return $tx;
            });
        }

        // For non-wallet provider: create/obtain an idempotent transaction via User::charge (which calls provider)
        $tx = $user->charge($amount, $provider, array_merge($meta, ['idempotency_key' => $idempotency]));

        // If the returned transaction is already marked success (sync provider), update group/pivot atomically.
        if ($tx->status === Transaction::STATUS_SUCCESS) {
            DB::transaction(function () use ($group, $user, $amount) {
                $userPivotExists = $group->users()->where('user_id', $user->id)->exists();

                if ($userPivotExists) {
                    $group->users()->updateExistingPivot($user->id, [
                        'contributed' => DB::raw("COALESCE(contributed,0) + {$amount}"),
                        'total_contributed' => DB::raw("COALESCE(total_contributed,0) + {$amount}"),
                        'last_payment_at' => now(),
                    ]);
                } else {
                    $group->users()->attach($user->id, [
                        'contributed' => $amount,
                        'total_contributed' => $amount,
                        'last_payment_at' => now(),
                        // 'role' => 'member',
                    ]);
                }

                $group->increment('saved', $amount);
            });
        } else {
            // Transaction is pending — it will be reconciled by webhook/worker.
            // You may optionally mark an optimistic pending contribution row or notify user.
            Log::info("Contribution created as pending for group {$group->id} user {$user->id} tx {$tx->id}");
        }

        return $tx;
    }

    /**
     * Deposit into user's available wallet.
     *
     * - If $fromPending is true: move from user's pending_wallet to available_wallet (internal)
     * - Otherwise create a transaction via provider and credit wallet if immediate success.
     *
     * @param User $user
     * @param float $amount
     * @param string|null $provider
     * @param string|null $reference
     * @param bool $fromPending
     * @return Transaction
     * @throws \Throwable
     */
    public function deposit(
        User $user,
        float $amount,
        $method, ?string
        $provider = "flutterwave",
        ?string $reference = null,
        bool $fromPending = false,
        array $meta
    )
    {
        $amount = (float) $amount;
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        if ($fromPending) {
            return DB::transaction(function () use ($user, $amount, $reference) {
                $pending = (float) ($user->pending_wallet ?? 0);
                if ($pending < $amount) {
                    throw new \RuntimeException('Insufficient pending wallet balance');
                }

                $user->pending_wallet = $pending - $amount;
                $user->available_wallet = ($user->available_wallet ?? 0) + $amount;
                $user->save();

                // record transaction
                $tx = $user->transactions()->create([
                    'uuid' => (string) Str::uuid(),
                    'reference' => $reference ?? 'pending_to_available:' . time(),
                    'label' => $meta['label'] ?? null,
                    'idempotency_key' => "pending_to_available_user_{$user->id}_" . md5($amount . ':' . ($reference ?? '')),
                    'amount' => $amount,
                    'fee' => 0,
                    'net_amount' => $amount,
                    'currency' => 'NGN',
                    'type' => Transaction::TYPE_TOPUP,
                    'direction' => Transaction::DIRECTION_CREDIT,
                    'provider' => 'pending',
                    'method' => 'internal',
                    'status' => Transaction::STATUS_SUCCESS,
                    'meta' => ['note' => 'pending->available'],
                    'processed_at' => now(),
                ]);

                // notify user about successful move from pending to available
                try {
                    $user->notify(new TransactionNotification($tx, 'success'));
                } catch (\Throwable $ex) {
                    Log::warning('Failed to send pending->available notification: ' . $ex->getMessage());
                }

                return $tx;
            });
        }

        $payment = PaymentFactory::provider($provider);
        $tx = (object) $payment->deposit($method, array_merge($meta, [
            "amount" => $amount,
            'reference' => $reference
        ]));

        // If provider returned immediate success, credit wallet now
        if ($tx->status === Transaction::STATUS_SUCCESS  && ($tx->save_to_db ?? false)) {
            // credit and record transaction via user's domain method (atomic)
            return $user->creditToWallet($tx->amount, [
                'provider' => $tx->provider,
                'reference' => $tx->reference,
                'idempotency_key' => $tx->idempotency_key,
                'type' => Transaction::TYPE_TOPUP,
            ]);
        }else{
            // Log::info(["payment transaction" => $tx]);
            $type = Transaction::TYPE_TOPUP;
            $direction = Transaction::DIRECTION_CREDIT;
            $fee = 0;
            $net = $amount - $fee;


            $user->transactions()->create([
                'uuid' => (string) Str::uuid(),
                'reference' => $meta['reference'] ?? $tx->reference,
                'label' => $meta['label'] ?? null,
                'idempotency_key' => $meta['idempotency_key'] ?? "{$type}_user_{$user->id}_" . md5($amount . ':' . ($meta['reference'] ?? '')),
                'amount' => $amount,
                'fee' => 0,
                'net_amount' => $net,
                'currency' => $meta['currency'] ?? 'NGN',
                'type' => $type,
                'direction' => $direction,
                'provider' => $meta['provider'] ?? 'system',
                'method' => $meta['method'] ?? null,
                'provider_reference' => $tx->id ?? null,
                'status' => Transaction::STATUS_PENDING,
                'attempts' => 0,
                'meta' => array_merge($meta, [ "trackId" => $tx->id,  ]),
                'processed_at' => now(),
            ]);
            // notify user that deposit is pending
            try {
                // find the newly created pending tx (by idempotency key) to pass to notification
                $pendingIdemp = $meta['idempotency_key'] ?? ($tx->id ?? null);
                // best-effort: find latest pending tx for this user and reference
                $pendingTx = \App\Models\Transaction::where('user_id', $user->id)->where('status', Transaction::STATUS_PENDING)->orderBy('created_at', 'desc')->first();
                if ($pendingTx) {
                    $user->notify(new TransactionNotification($pendingTx, 'pending'));
                }
            } catch (\Throwable $ex) {
                Log::warning('Failed to send pending deposit notification: ' . $ex->getMessage());
            }
        }

        // Otherwise tx is pending; will be credited when webhook reconciles
        return $tx;
    }

    /**
     * Withdraw funds from user's wallet (delegates to User::withdrawFromWallet).
     *
     * @param User $user
     * @param float $amount
     * @param string|null $reference
     * @return Transaction
     */
    public function withdraw(User $user, float $amount, ?string $reference = null, $meta=[])
    {
        return $user->withdrawFromWallet($amount, array_merge($meta, ['reference' => $reference]));
    }

    public function verifyCardPayment(array $payload, string $provider = "flutterwave"){
        $payment = PaymentFactory::provider($provider);
        return (object) $payment->verifyCardPayment($payload);
    }


    public function generateVirtualAccounts ($payload){

        $user = User::find($payload['id']);
        $banks = [];
        foreach(["flutterwave"] as $p){
            $payment = PaymentFactory::provider($p);
            if(!$user->hasCustomerId()){
                $res = $payment->createCustomer($user->toArray());
                Log::info(["[customer creation response]: " =>$res]);
                UserBankCard::create([
                    "user_id" => $user->id,
                    "customer_id" => $res['customer_id']
                ]);
            }
            if($user->hasVirtualBank($p)) continue;
            $userBankCard = UserBankCard::whereUserId($payload['id'])->first();
            $res =  $payment->generateVirtualAccount(array_merge($payload, [ "customer_id" => $userBankCard->customer_id, "user" => $user ]));
            $vb = VirtualBank::create($res);
            $banks[] = $vb->toResource();
        }

        return $banks[0] ?? [];
    }

    public function verifyBank($payload){
        $payment = PaymentFactory::provider($payload['provider'] ?? "flutterwave");
        return (object) $payment->verifyBankAccount($payload);
    }

    public function listBanks($provider = "flutterwave"):array{
        $payment = PaymentFactory::provider($provider);
        return $payment->listBanks();
    }

    public function verifyBankAccount(array $payload, $provider ="flutterwave"){
        $payment = PaymentFactory::provider($provider);
        Log::info("Sigh");
        return $payment->verifyBankAccount($payload);
    }
}
