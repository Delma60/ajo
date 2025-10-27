<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        $referrals = \App\Models\Referral::where('referrer_id', $this->id)->with('referred')->get();
        $referredUsers = $referrals->map(function($r) {
            return [
                'id' => $r->referred?->id,
                'name' => $r->referred?->name,
                'email' => $r->referred?->email,
                'accepted_at' => $r->accepted_at,
                'code' => $r->code,
            ];
        })->filter(fn($x) => !is_null($x['id']))->values();

        $invitedCount = $referredUsers->count();

        return [
            "id" => $this->id,
            "imageUrl" => $this->imageUrl,
            "name" => $this->name,
            "email" => $this->email,
            "phone" => $this->phone,
            "referral_code" => $this->referral_code,
            "balance" => [
                "available_wallet" => $this->available_wallet,
                "pending_wallet" => $this->pending_balance,
                "available_referral" => $this->available_referral,
                "pending_referral" => $this->pending_referral,
            ],
            "isVerified" => $this->isVerified,
            "status" => $this->status,
            "created_at" => $this->created_at,
            "created_at_human" => Carbon::parse($this->created_at)->diffForHumans(),
            "updated_at" => $this->updated_at,
            "contributions" => $this->contributions,
            "next_due" => $this->next_thrift,
            "inviteReceived" => $this->whenLoaded("inviteReceived", $this->inviteReceived, []),
            "inviteSent" => $this->whenLoaded("inviteSent", $this->inviteSent, []),
            "referral" => [
                "code"  => $this->referral_code,
                // "available_referral" => $this->available_referral,
                // "pending_referral" => $this->pending_referral,
                "invited_count" => $invitedCount,
                "referred_users" => $referredUsers,
            ],
            "transactions" => $this->whenLoaded('transactions', TransactionResource::collection($this->transactions), []),
            "cards" => $this->whenLoaded('bankCards', BankCardResource::collection($this->bankCards), []),
            "notifications" => $this->whenLoaded('notifications', NotificationResource::collection($this->notifications), []),
            "banks" => $this->whenLoaded('banks', BankResource::collection($this->banks), []),
            "groups" => $this->whenLoaded('groups', GroupResource::collection($this->groups), []),
            "virtual_bank" => $this->whenLoaded('virtualBank', new VirtualBankResource($this->virtualBank), null),
        ];
    }


    public function with(Request $request):array{
        return [
            "meta" => [
                "message" => "Successfully Fetched"
            ]
        ];
    }
}
