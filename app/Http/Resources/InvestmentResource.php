<?php

namespace App\Http\Resources;

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
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'min_investment' => $this->min_investment,
            'status' => $this->status,
            'raised' => $this->raised,
            'target' => $this->target,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'apy' => $this->apy,
            'duration' => $this->duration,
            'investors' => $this->whenLoaded('investors', function () {
                return $this->investors->map(function ($u) {
                    return [
                        'id' => $u->id,
                        'name' => $u->name ?? null,
                        'amount' => isset($u->pivot->amount) ? (float) $u->pivot->amount : null,
                    ];
                })->values();
            }),
        ];
    }

    public function with(Request $request) {
        return [
            "message" => "Successful"
        ];
    }
}
