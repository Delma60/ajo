<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VirtualBankResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        return [
            "id" => $this->id,
            "bank_name" => $this->bank_name,
            "account_number" => $this->account_number,
            "provider" => $this->provider,
            "status" => $this->status,  
            "reference" => $this->reference,
            "meta" => $this->meta,
            "created_at" => $this->created_at,

        ];
    }
}
