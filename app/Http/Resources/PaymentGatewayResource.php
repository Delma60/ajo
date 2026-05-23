<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentGatewayResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $totalTxns = $this->total_transactions ?? 0;
        $successfulTxns = $this->successful_transactions ?? 0;
        $failedTxns = $this->failed_transactions ?? 0;

        // Calculate explicit percentages
        $successRate = $totalTxns > 0 ? round(($successfulTxns / $totalTxns) * 100) : 100;
        $failureRate = $totalTxns > 0 ? round(($failedTxns / $totalTxns) * 100) : 0;

        // Pull values from your 'meta' field saved during your store method
        $meta = $this->meta ?? [];

        return [
            "id" => (string) $this->id, // Frontend interface expects a string ID
            "name" => $this->name,
            "slug" => $this->slug,
            "status" => $this->is_active ? "active" : "inactive", // Map boolean to frontend status type
            "isDefault" => (bool) $this->is_default, // Frontend uses camelCase 'isDefault'
            "mode" => $this->mode,
            
            // Credentials
            "public_key" => $this->public_key ?? '',
            "secret_key" => $this->secret_key ?? '',
            "webhook_secret" => $this->webhook_secret ?? '',
            "webhook_url" => secure_url("api/v1/webhooks/{$this->slug}"), // Dynamic example generation

            // Computed Transaction Metrics
            "total_transactions" => (int) $totalTxns,
            "total_volume" => (float) ($this->total_volume ?? 0),
            "success_rate" => (int) $successRate,
            "failureRate" => (int) $failureRate,
            
            // Placeholders / Defaults if columns don't exist yet
            "avgResponseMs" => (int) ($this->avg_response_ms ?? 240), 
            "lastChecked" => $this->updated_at ? $this->updated_at->toIso8601String() : now()->toIso8601String(),

            // Unpacking meta fields
            "supported_methods" => $meta['supported_methods'] ?? ["Card", "Bank Transfer"],
            "fees" => $meta['fees'] ?? [
                "card" => "0%",
                "bank" => "₦0",
                "ussd" => "₦0"
            ],
        ];
    }
}
