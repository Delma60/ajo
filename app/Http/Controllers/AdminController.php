<?php

namespace App\Http\Controllers;

use App\Http\Resources\GroupResource;
use App\Http\Resources\TransactionResource;
use App\Models\User;
use App\Models\Group;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * Fetch high-level metrics for the admin dashboard overview cards.
     *
     * Query params:
     *   timeframe: '7d' | '30d' | '90d'  (default: '7d')
     */
    public function metrics(Request $request): JsonResponse
    {
        // ── Resolve timeframe ────────────────────────────────────────────────────
        $timeFrame = $request->query('timeframe', '7d');
        $days = match ($timeFrame) {
            '30d'   => 30,
            '90d'   => 90,
            default => 7,
        };

        $now              = Carbon::now();
        $startOfMonth     = $now->copy()->startOfMonth();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth   = $now->copy()->subMonth()->endOfMonth();

        // ── 1. User Metrics ──────────────────────────────────────────────────────
        $totalUsers        = User::count();
        $newUsersThisMonth = User::where('created_at', '>=', $startOfMonth)->count();
        $usersLastMonth    = User::whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])->count();
        $verifiedUsers     = User::whereNotNull('email_verified_at')->count();
        $pendingKyc        = User::where('kyc_status', 'pending')->count();
        $suspendedUsers    = User::where('status', 'suspended')->count();

        // Month-on-month user growth percentage
        $userGrowthPct = $usersLastMonth > 0
            ? round((($newUsersThisMonth - $usersLastMonth) / $usersLastMonth) * 100, 1)
            : ($newUsersThisMonth > 0 ? 100.0 : 0.0);

        // ── 2. Circle (Group) Metrics ────────────────────────────────────────────
        $activeCircles       = Group::where('status', '!=', 'closed')->orWhereNull('status')->count();
        $newCirclesThisMonth = Group::where('created_at', '>=', $startOfMonth)->count();

        // Average members per active circle
        $avgMembersPerCircle = round(
            Group::where(fn ($q) => $q->where('status', '!=', 'closed')->orWhereNull('status'))
                ->withCount('users')
                ->get()
                ->avg('users_count') ?? 0,
            1
        );

        // Average pool size across active circles
        $avgPoolSize = (float) (
            Group::where(fn ($q) => $q->where('status', '!=', 'closed')->orWhereNull('status'))
                ->avg('saved') ?? 0
        );

        // Completion rate: closed circles / total circles * 100
        $totalGroups    = Group::count();
        $closedGroups   = Group::where('status', 'closed')->count();
        $completionRate = $totalGroups > 0
            ? round(($closedGroups / $totalGroups) * 100, 1)
            : 0.0;

        // ── 3. Financial Metrics (MTD vs Last Month) ─────────────────────────────
        $payoutsMtd = Transaction::where('type', Transaction::TYPE_PAYOUT)
            ->where('status', Transaction::STATUS_SUCCESS)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount');

        $payoutsLastMonth = Transaction::where('type', Transaction::TYPE_PAYOUT)
            ->where('status', Transaction::STATUS_SUCCESS)
            ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
            ->sum('amount');

        $payoutsChangePct = $payoutsLastMonth > 0
            ? (($payoutsMtd - $payoutsLastMonth) / $payoutsLastMonth) * 100
            : ($payoutsMtd > 0 ? 100 : 0);

        $revenueMtd = Transaction::where('status', Transaction::STATUS_SUCCESS)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('fee');

        $revenueLastMonth = Transaction::where('status', Transaction::STATUS_SUCCESS)
            ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
            ->sum('fee');

        $revenueChangePct = $revenueLastMonth > 0
            ? (($revenueMtd - $revenueLastMonth) / $revenueLastMonth) * 100
            : ($revenueMtd > 0 ? 100 : 0);

        // ── 4. Revenue Breakdown by Transaction Type (MTD) ───────────────────────
        $revenueBreakdown = Transaction::where('status', Transaction::STATUS_SUCCESS)
            ->where('created_at', '>=', $startOfMonth)
            ->where('fee', '>', 0)
            ->selectRaw('type, SUM(fee) as total_fee, COUNT(*) as tx_count')
            ->groupBy('type')
            ->get()
            ->map(fn ($r) => [
                'type'   => $r->type,
                'amount' => (float) $r->total_fee,
                'count'  => (int) $r->tx_count,
            ])
            ->values();

        // ── 5. Volume Chart (dynamic timeframe) ──────────────────────────────────
        $chartStartDate = Carbon::today()->subDays($days - 1)->startOfDay();
        $chartEndDate   = Carbon::today()->endOfDay();

        // Single efficient query for the entire period
        $periodTransactions = Transaction::where('status', Transaction::STATUS_SUCCESS)
            ->whereIn('type', [Transaction::TYPE_CHARGE, Transaction::TYPE_TOPUP, Transaction::TYPE_PAYOUT])
            ->whereBetween('created_at', [$chartStartDate, $chartEndDate])
            ->get(['amount', 'type', 'created_at']);

        $chartData      = [];
        $volumeTotal    = 0;
        $volumeTxCount  = $periodTransactions->count();

        for ($i = $days - 1; $i >= 0; $i--) {
            $date       = Carbon::today()->subDays($i);
            $dateString = $date->format('Y-m-d');

            $dayTxs = $periodTransactions->filter(
                fn ($t) => $t->created_at->format('Y-m-d') === $dateString
            );

            $contributions  = (float) $dayTxs->whereIn('type', [Transaction::TYPE_CHARGE, Transaction::TYPE_TOPUP])->sum('amount');
            $payouts        = (float) $dayTxs->where('type', Transaction::TYPE_PAYOUT)->sum('amount');
            $volumeTotal   += $contributions + $payouts;

            // For 7d show "13 May"; for 30d/90d show "13 May" as well but fewer labels
            // The frontend can choose to skip intermediate ticks for readability.
            $chartData[] = [
                'label'         => $date->format('j M'),
                'contributions' => $contributions,
                'payouts'       => $payouts,
            ];
        }

        $volumeAvgSize = $volumeTxCount > 0
            ? round($volumeTotal / $volumeTxCount, 2)
            : 0;

        // ── 6. User Growth Chart (last 6 months — new signups per month) ─────────
        $userGrowthChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i);
            $userGrowthChart[] = [
                'label' => $month->format('M'),
                'users' => User::whereYear('created_at', $month->year)
                    ->whereMonth('created_at', $month->month)
                    ->count(),
            ];
        }

        // ── 7. Health Metrics ────────────────────────────────────────────────────
        // TODO: replace placeholders with real queries once data model supports them
        $defaulterRate = 2.5;  // e.g. (users with missed payments / total active users) * 100
        $onTimeRate    = 95.0; // e.g. (payments before deadline / total due payments) * 100
        $avgTrustScore = 4.8;  // e.g. average of user trust_score column (0–5 scale)

        // ── Response ─────────────────────────────────────────────────────────────
        return response()->json([
            // Users
            'total_users'             => $totalUsers,
            'new_users_this_month'    => $newUsersThisMonth,
            'verified_users'          => $verifiedUsers,
            'pending_kyc'             => $pendingKyc,
            'suspended_users'         => $suspendedUsers,
            'user_growth_pct'         => $userGrowthPct,

            // Circles
            'active_circles'          => $activeCircles,
            'new_circles_this_month'  => $newCirclesThisMonth,
            'avg_members_per_circle'  => $avgMembersPerCircle,
            'avg_pool_size'           => $avgPoolSize,

            // Financials
            'payouts_mtd'             => (float) $payoutsMtd,
            'payouts_change_pct'      => round((float) $payoutsChangePct, 2),
            'revenue_mtd'             => (float) $revenueMtd,
            'revenue_change_pct'      => round((float) $revenueChangePct, 2),
            'revenue_breakdown'       => $revenueBreakdown,

            // Volume chart
            'volume_chart'            => $chartData,
            'volume_total'            => (float) $volumeTotal,
            'volume_tx_count'         => $volumeTxCount,
            'volume_avg_size'         => (float) $volumeAvgSize,

            // User growth chart
            'user_growth_chart'       => $userGrowthChart,

            // Circle health
            'defaulter_rate'          => $defaulterRate,
            'on_time_rate'            => $onTimeRate,
            'completion_rate'         => $completionRate,
            'avg_trust_score'         => $avgTrustScore,
        ]);
    }

    /**
     * Fetch the most recent transactions across the platform.
     *
     * Returns `user` and `group` as nested objects so the frontend can
     * access tx.user.name and tx.group.name directly.
     */
    public function recentTransactions(): JsonResponse
    {
        $transactions = Transaction::with(['user:id,name,email', 'group:id,name'])
            ->latest()
            ->take(10)
            ->get();

        return response()->json(TransactionResource::collection($transactions)->resolve());
    }

    /**
     * Fetch the top performing/highest value circles (groups).
     *
     * Uses resolve() instead of ->response() to return a plain JSON array
     * without the {"data": [...]} wrapper that ResourceCollection adds by
     * default — keeping the shape consistent with other endpoints.
     */
    public function topCircles(): JsonResponse
    {
        $circles = Group::withCount('users')
            ->orderByDesc('saved')
            ->take(5)
            ->get();

        return response()->json(GroupResource::collection($circles)->resolve());
    }
}
