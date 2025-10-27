<?php

namespace App\Http\Resources;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class GroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        // $now = Carbon::now();
        // $nextPayout = $this->next_payout ? Carbon::parse($this->next_payout) : null;

        //  $subtractInterval = function (Carbon $date, string $frequency): Carbon {
        //     switch (strtolower($frequency)) {
        //         case 'daily':
        //             return $date->copy()->subDay();
        //         case 'weekly':
        //             return $date->copy()->subWeek();
        //         case 'bi-weekly':
        //         case 'biweekly':
        //         case 'bi_weekly':
        //             return $date->copy()->subWeeks(2);
        //         case 'monthly':
        //         default:
        //             return $date->copy()->subMonth();
        //     }
        // };

        $period = $this->currentPeriod();
        $periodStart = $period['start'] ?? null;
        $periodEnd = $period['end'] ?? null;

        // $periodEnd = null;
        // $periodStart = null;
        // if ($nextPayout && $nextPayout->greaterThan($now)) {
        //     $periodEnd = $nextPayout->copy()->endOfDay();
        //     $periodStart = $subtractInterval($nextPayout, $this->frequency)->startOfDay();
        // } else {
        //     $periodEnd = $now->copy()->endOfDay();
        //     $periodStart = $subtractInterval($now, $this->frequency)->startOfDay();
        // }
        return [
            'id' => $this->id,
            "name" => $this->name,
            "description" => $this->description,
            "nextDue" => $this->next_payout,
            "goal" => $this->goal,
            "saved" => $this->saved,
            "frequency" => $this->frequency,
            "contribution" => $this->meta['contribution'],
            "created_at" => $this->created_at,
            "start_date" => $this->meta['start_date'],
            "isPrivate" => $this->is_private,
            "pendingInvites" => $this->whenLoaded("pendingInvites", $this->pendingInvites, []),
            "pendingRequests" => $this->whenLoaded("pendingRequests", $this->pendingRequests, []),
            "group_transaction" => $this->group_transaction->map(function ($tx) {
                $user = User::find($tx->user_id);
                return [
                    "who" => $user->name,
                    "date" => $tx->created_at,
                    "amount" => $tx->amount
                ];
            }),
            "payout_order" => $this->meta['payout_order'],
            "status" => $this->status,
            "nextPayout" => $this->next_payout,
            "next_payout_human" => $this->next_payout_human,
            "membersCount" => $this->users->count(), //$this->whenCounted("users", $this->users->count(), 0),
            "max_members" => $this->meta['max_members'],
            "admin" => new UserResource($this->whenLoaded("owner")),
            'period_start' => $periodStart->toISOString(),
            'period_end' => $periodEnd->toISOString(),
            "transactions" => $this->whenLoaded("transactions", TransactionResource::collection($this->transactions), []),
            "cycles" => $this->whenLoaded("cycles", $this->cycles),
            "members" => $this->whenLoaded("users", function () use($periodStart, $periodEnd) {
                return $this->users->map(function ($user) use($periodStart, $periodEnd) {
                    $lastPaymentAt = $user->pivot->last_payment_at ?? null;
                    $hasPaid = false;

                    if ($lastPaymentAt) {
                        try {
                            $lp = Carbon::parse($lastPaymentAt);
                            if ($lp->between($periodStart, $periodEnd)) {
                                $hasPaid = true;
                            }
                        } catch (\Throwable $e) {
                            Log::info($e->getMessage());
                            // ignore parse error and leave hasPaid = false
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
                        "role" => $user->pivot->role,
                        'hasPaid' => $hasPaid,
                        'last_payment_at' => $lastPaymentAt,
                    ];
                });
            }),
        ];
    }


    public function with(Request $request){
        return [
            "message" => "successfully"
        ];

    }
}
