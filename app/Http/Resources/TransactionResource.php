<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "reference" => $this->reference,
            "label" => $this->label,
            "created_at" => $this->created_at,
            "type" => $this->type,
            "amount" => $this->amount,
            'fee' => $this->fee,
            'status' => $this->status,
            'direction' => $this->direction,
            "short_label" => $this->short_label,
            "user" => $this->whenLoaded("user", $this->user, []),
            "group" => $this->whenLoaded("group", $this->group, []),

        ];
    }
}
