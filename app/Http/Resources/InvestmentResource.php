<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = Carbon::now();

        // Progress percentage (0–100, capped)
        $progressPercent = $this->target > 0
            ? min(100, round(((float) $this->raised / (float) $this->target) * 100, 2))
            : 0;

        // Days remaining until end_date (null if no end_date or already passed)
        $daysRemaining = null;
        $isExpired = false;
        if ($this->end_date) {
            $endDate = Carbon::parse($this->end_date);
            $isExpired = $endDate->isPast();
            $daysRemaining = $isExpired ? 0 : (int) $now->diffInDays($endDate);
        }

        // Days since start
        $daysActive = null;
        if ($this->start_date) {
            $startDate = Carbon::parse($this->start_date);
            $daysActive = $startDate->isPast() ? (int) $startDate->diffInDays($now) : 0;
        }

        // Funding gap
        $remaining = max(0, (float) $this->target - (float) $this->raised);
        $isFundingComplete = $this->target > 0 && (float) $this->raised >= (float) $this->target;

        // Risk label & color hint for frontend
        $riskMeta = match (strtolower((string) $this->risk)) {
            'low'    => ['label' => 'Low Risk',    'color' => 'green'],
            'medium' => ['label' => 'Medium Risk', 'color' => 'yellow'],
            'high'   => ['label' => 'High Risk',   'color' => 'red'],
            default  => ['label' => 'Unknown',     'color' => 'gray'],
        };

        // Status label & color hint
        $statusMeta = match (strtolower((string) $this->status)) {
            'active'  => ['label' => 'Active',  'color' => 'green'],
            'paused'  => ['label' => 'Paused',  'color' => 'yellow'],
            'closed'  => ['label' => 'Closed',  'color' => 'gray'],
            'draft'   => ['label' => 'Draft',   'color' => 'blue'],
            default   => ['label' => ucfirst((string) $this->status), 'color' => 'gray'],
        };

        // APY display string e.g. "12% p.a."
        $apyDisplay = $this->apy !== null ? number_format((float) $this->apy, 1) . '% p.a.' : null;

        // Investor count
        $investorsCount = $this->whenLoaded(
            'investors',
            fn() => $this->investors->count(),
            fn() => $this->investors()->count()   // always query if not loaded
        );

        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'subtitle'    => $this->subtitle,
            'description' => $this->description,

            // Money
            'min_investment'    => (float) $this->min_investment,
            'raised'            => (float) $this->raised,
            'target'            => (float) $this->target,
            'remaining'         => $remaining,
            'is_funding_complete' => $isFundingComplete,

            // Progress
            'progress_percent' => $progressPercent,

            // Dates
            'start_date'     => $this->start_date,
            'end_date'       => $this->end_date,
            'days_remaining' => $daysRemaining,
            'days_active'    => $daysActive,
            'is_expired'     => $isExpired,

            // Performance
            'apy'         => (float) $this->apy,
            'apy_display' => $apyDisplay,
            'duration'    => (int) $this->duration,  // months

            // Risk
            'risk'       => $this->risk,
            'risk_label' => $riskMeta['label'],
            'risk_color' => $riskMeta['color'],

            // Status
            'status'       => $this->status,
            'status_label' => $statusMeta['label'],
            'status_color' => $statusMeta['color'],

            // Investors
            'investors_count' => $investorsCount,
            'investors'       => $this->whenLoaded('investors', function () {
                return $this->investors->map(fn($u) => [
                    'id'     => $u->id,
                    'name'   => $u->name ?? null,
                    'amount' => isset($u->pivot->amount) ? (float) $u->pivot->amount : null,
                ])->values();
            }),
        ];
    }

    public function with(Request $request): array
    {
        return [
            'message' => 'Successful',
        ];
    }
}
