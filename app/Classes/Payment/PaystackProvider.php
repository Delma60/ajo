<?php

namespace App\Classes\Payment;

class PaystackProvider implements PaymentInterface
{
    public function charge(array $payload): array
    {
        // Placeholder for paystack charge initiation
        return [
            'status' => 'pending',
            'reference' => $payload['reference'] ?? uniqid('pay_'),
            'provider' => 'paystack',
            'authorization_url' => $payload['authorization_url'] ?? null,
        ];
    }

    public function handleWebhook(array $payload): array
    {
        // Interpret paystack payload and return normalized data
        return [
            'handled' => true,
            'status' => $payload['event'] ?? 'unknown',
            'reference' => $payload['data']['reference'] ?? null,
            'raw' => $payload,
        ];
    }
}
