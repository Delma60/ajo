<?php

namespace App\Http\Resources;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $period = $this->currentPeriod();
        $periodStart = $period['start'] ?? null;
        $periodEnd = $period['end'] ?? null;

        // Pre-load user IDs from group transactions to avoid N+1
        $txUserIds = $this->group_transaction->pluck('user_id')->unique();
        $txUsers = User::whereIn('id', $txUserIds)->get()->keyBy('id');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'nextDue' => $this->next_payout,
            'goal' => $this->goal,
            'saved' => $this->saved,
            'frequency' => $this->frequency,
            'contribution' => $this->meta['contribution'],
            'created_at' => $this->created_at,
            'start_date' => $this->meta['start_date'],
            'isPrivate' => $this->is_private,
            'pendingInvites' => $this->whenLoaded('pendingInvites', fn() => $this->pendingInvites, []),
            'pendingRequests' => $this->whenLoaded('pendingRequests', fn() => $this->pendingRequests, []),
            'group_transaction' => $this->group_transaction->map(function ($tx) use ($txUsers) {
                $user = $txUsers->get($tx->user_id);
                return [
                    'who' => $user?->name ?? 'Unknown',
                    'date' => $tx->created_at,
                    'amount' => $tx->amount,
                ];
            }),
            'payout_order' => $this->meta['payout_order'],
            'status' => $this->status,
            'nextPayout' => $this->next_payout,
            'next_payout_human' => $this->next_payout_human,
            'membersCount' => $this->users->count(),
            'max_members' => $this->meta['max_members'],
            'admin' => new UserResource($this->whenLoaded('owner')),
            'period_start' => $periodStart?->toISOString(),
            'period_end' => $periodEnd?->toISOString(),
            'transactions' => $this->whenLoaded('transactions', TransactionResource::collection($this->transactions), []),
            'cycles' => $this->whenLoaded('cycles', $this->cycles),
            'members' => $this->whenLoaded('users', function () use ($periodStart, $periodEnd) {
                return $this->users->map(function ($user) use ($periodStart, $periodEnd) {
                    $lastPaymentAt = $user->pivot->last_payment_at ?? null;
                    $hasPaid = false;

                    if ($lastPaymentAt && $periodStart && $periodEnd) {
                        try {
                            $lp = Carbon::parse($lastPaymentAt);
                            $hasPaid = $lp->between($periodStart, $periodEnd);
                        } catch (\Throwable $e) {
                            Log::info($e->getMessage());
                        }
                    }

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'imageUrl' => $user->imageUrl ?? null,
                        'email' => $user->email ?? null,
                        'phone' => $user->phone ?? null,
                        'contributed' => (float) $user->pivot->contributed,
                        'joined_at' => $user->pivot->joined_at,
                        'joined_at_human' => Carbon::parse($user->pivot->joined_at)->diffForHumans(),
                        'paid_at' => $user->pivot->paid_at,
                        'status' => $user->pivot->status,
                        'total_contributed' => $user->pivot->total_contributed,
                        'role' => $user->pivot->role,
                        'hasPaid' => $hasPaid,
                        'last_payment_at' => $lastPaymentAt,
                    ];
                });
            }),
        ];
    }

    public function with(Request $request): array
    {
        return ['message' => 'successfully'];
    }
}
