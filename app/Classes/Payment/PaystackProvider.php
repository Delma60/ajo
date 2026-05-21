<?php

namespace App\Classes\Payment;

class PaystackProvider implements PaymentInterface
{
    public function charge($method, array $payload): array
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

    // app/Classes/Payment/PaystackProvider.php

    // ... existing charge and handleWebhook methods ...

    public function deposit($method, array $payload): array
    {
        return [];
    }

    public function transfer(array $payload): array
    {
        return [];
    }

    public function verifyBankAccount(array $payload): array
    {
        return [];
    }

    public function listBanks(): array
    {
        return [];
    }

    public function createCustomer(array $payload): array
    {
        return [];
    }

    public function verifyCardPayment(array $payload): mixed
    {
        return null;
    }

    public function generateVirtualAccount(array $payload): array
    {
        return [];
    }
}
