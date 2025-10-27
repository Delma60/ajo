<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankCardResource extends JsonResource
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
            "card_number" => $this->meta['card_number'],
            "cvv" => $this->meta['cvv'],
            "exp_month" => $this->exp_month,
            "brand" => $this->brand,
            "exp_year" => $this->exp_year,
            // "cvv" => 
        ];
    }
}
