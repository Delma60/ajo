<?php

namespace App\Services;

use App\Models\Group;
use App\Models\SystemAlert;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SystemAlertService
{
    /**
     * Generate / refresh all system alerts and return the full list.
     * Safe to call on every dashboard load — uses upsert-style logic so
     * duplicates are not created for the same open issue.
     */
    public function generate(): void
    {
        $this->checkFailedTransactions();
        $this->checkHighPendingPayouts();
        $this->checkSuspendedUsers();
        $this->checkStaleGroups();
        $this->checkLowGroupFunding();
        $this->checkNewUsersSpike();
        $this->checkLargeWithdrawals();
        $this->checkWebhookFailures();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Individual checks
    // ─────────────────────────────────────────────────────────────────────────

    protected function checkFailedTransactions(): void
    {
        $since = Carbon::now()->subHour();
        $count = Transaction::where('status', Transaction::STATUS_FAILED)
            ->where('created_at', '>=', $since)
            ->count();

        if ($count === 0) return;

        $type     = $count >= 10 ? SystemAlert::TYPE_CRITICAL : SystemAlert::TYPE_WARNING;
        $title    = "{$count} failed transaction(s) in the last hour";
        $existing = SystemAlert::whereNull('resolved_at')
            ->where('category', SystemAlert::CAT_PAYMENT)
            ->where('title', $title)
            ->first();

        if ($existing) return; // already open

        SystemAlert::raise(
            $type,
            SystemAlert::CAT_PAYMENT,
            $title,
            "There have been {$count} failed transactions in the last 60 minutes. Review the transactions dashboard.",
            ['count' => $count, 'since' => $since->toDateTimeString()]
        );
    }

    protected function checkHighPendingPayouts(): void
    {
        $threshold = 500_000; // NGN
        $total = Transaction::where('type', Transaction::TYPE_PAYOUT)
            ->where('status', Transaction::STATUS_PENDING)
            ->sum('amount');

        if ($total < $threshold) return;

        $formatted = number_format($total, 2);
        $title     = "High pending payout balance: ₦{$formatted}";
        $existing  = SystemAlert::whereNull('resolved_at')
            ->where('category', SystemAlert::CAT_PAYMENT)
            ->where('title', $title)
            ->first();

        if ($existing) return;

        SystemAlert::raise(
            SystemAlert::TYPE_WARNING,
            SystemAlert::CAT_PAYMENT,
            $title,
            "Total pending payout is ₦{$formatted}. Ensure sufficient liquidity and verify pending payouts.",
            ['total_pending' => $total]
        );
    }

    protected function checkSuspendedUsers(): void
    {
        $since = Carbon::now()->subDay();
        $count = User::where('status', 'suspended')
            ->where('updated_at', '>=', $since)
            ->count();

        if ($count === 0) return;

        $title    = "{$count} user(s) suspended in the last 24 hours";
        $existing = SystemAlert::whereNull('resolved_at')
            ->where('category', SystemAlert::CAT_SECURITY)
            ->where('title', $title)
            ->first();

        if ($existing) return;

        SystemAlert::raise(
            SystemAlert::TYPE_WARNING,
            SystemAlert::CAT_SECURITY,
            $title,
            "{$count} account(s) were suspended in the last 24 hours. Review for potential fraud or policy violations.",
            ['count' => $count]
        );
    }

    protected function checkStaleGroups(): void
    {
        // Active groups that have had zero contributions in the last 30 days
        $cutoff = Carbon::now()->subDays(30);
        $stale  = Group::where('status', 'active')
            ->whereDoesntHave('transactions', function ($q) use ($cutoff) {
                $q->where('type', Transaction::TYPE_CHARGE)
                  ->where('status', Transaction::STATUS_SUCCESS)
                  ->where('created_at', '>=', $cutoff);
            })
            ->count();

        if ($stale === 0) return;

        $title    = "{$stale} active group(s) with no contributions in 30 days";
        $existing = SystemAlert::whereNull('resolved_at')
            ->where('category', SystemAlert::CAT_GROUP)
            ->where('title', $title)
            ->first();

        if ($existing) return;

        SystemAlert::raise(
            SystemAlert::TYPE_INFO,
            SystemAlert::CAT_GROUP,
            $title,
            "{$stale} active circle(s) have had no contributions in over 30 days. They may need attention or closure.",
            ['stale_count' => $stale]
        );
    }

    protected function checkLowGroupFunding(): void
    {
        // Groups where saved < 20% of goal and status is active
        $groups = Group::where('status', 'active')
            ->whereRaw('saved < goal * 0.20')
            ->count();

        if ($groups === 0) return;

        $title    = "{$groups} group(s) funded below 20% of goal";
        $existing = SystemAlert::whereNull('resolved_at')
            ->where('category', SystemAlert::CAT_GROUP)
            ->where('title', $title)
            ->first();

        if ($existing) return;

        SystemAlert::raise(
            SystemAlert::TYPE_INFO,
            SystemAlert::CAT_GROUP,
            $title,
            "{$groups} active circle(s) have collected less than 20% of their savings goal.",
            ['count' => $groups]
        );
    }

    protected function checkNewUsersSpike(): void
    {
        $today     = User::whereDate('created_at', today())->count();
        $yesterday = User::whereDate('created_at', today()->subDay())->count();

        if ($yesterday === 0 || $today <= $yesterday) return;

        $pct = round((($today - $yesterday) / $yesterday) * 100);
        if ($pct < 100) return; // only alert on a 100%+ spike

        $title    = "New user spike: +{$pct}% signups vs yesterday";
        $existing = SystemAlert::whereNull('resolved_at')
            ->where('category', SystemAlert::CAT_USER)
            ->where('title', $title)
            ->first();

        if ($existing) return;

        SystemAlert::raise(
            SystemAlert::TYPE_INFO,
            SystemAlert::CAT_USER,
            $title,
            "Today's signups ({$today}) are {$pct}% higher than yesterday ({$yesterday}). This may indicate a marketing campaign or unusual traffic.",
            ['today' => $today, 'yesterday' => $yesterday, 'pct' => $pct]
        );
    }

    protected function checkLargeWithdrawals(): void
    {
        $threshold = 1_000_000; // NGN per single transaction
        $since     = Carbon::now()->subHours(6);

        $count = Transaction::where('type', Transaction::TYPE_TRANSFER)
            ->where('status', Transaction::STATUS_SUCCESS)
            ->where('amount', '>=', $threshold)
            ->where('created_at', '>=', $since)
            ->count();

        if ($count === 0) return;

        $title    = "{$count} large withdrawal(s) ≥ ₦1M in last 6 hours";
        $existing = SystemAlert::whereNull('resolved_at')
            ->where('category', SystemAlert::CAT_SECURITY)
            ->where('title', $title)
            ->first();

        if ($existing) return;

        SystemAlert::raise(
            SystemAlert::TYPE_CRITICAL,
            SystemAlert::CAT_SECURITY,
            $title,
            "{$count} withdrawal transaction(s) of ₦1,000,000+ were processed in the last 6 hours. Review immediately.",
            ['count' => $count, 'threshold' => $threshold]
        );
    }

    protected function checkWebhookFailures(): void
    {
        // Transactions stuck in PENDING more than 2 hours (likely webhook missed)
        $cutoff = Carbon::now()->subHours(2);
        $stuck  = Transaction::where('status', Transaction::STATUS_PENDING)
            ->where('created_at', '<=', $cutoff)
            ->whereIn('type', [Transaction::TYPE_TOPUP, Transaction::TYPE_CHARGE])
            ->count();

        if ($stuck === 0) return;

        $title    = "{$stuck} transaction(s) pending for over 2 hours";
        $existing = SystemAlert::whereNull('resolved_at')
            ->where('category', SystemAlert::CAT_PAYMENT)
            ->where('title', $title)
            ->first();

        if ($existing) return;

        SystemAlert::raise(
            SystemAlert::TYPE_WARNING,
            SystemAlert::CAT_PAYMENT,
            $title,
            "{$stuck} transaction(s) have been in PENDING status for over 2 hours. Webhooks may have failed or need re-processing.",
            ['stuck_count' => $stuck]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Aggregated summary for dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function summary(): array
    {
        $unresolvedQuery = SystemAlert::whereNull('resolved_at');

        return [
            'total'    => (clone $unresolvedQuery)->count(),
            'critical' => (clone $unresolvedQuery)->where('type', SystemAlert::TYPE_CRITICAL)->count(),
            'warning'  => (clone $unresolvedQuery)->where('type', SystemAlert::TYPE_WARNING)->count(),
            'info'     => (clone $unresolvedQuery)->where('type', SystemAlert::TYPE_INFO)->count(),
        ];
    }
}