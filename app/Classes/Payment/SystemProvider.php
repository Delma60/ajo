<?php

namespace App\Classes\Payment;

class SystemProvider implements PaymentInterface
{
    public function charge(array $payload): array
    {
        // local system-based payment: instantly succeed
        return [
            'status' => 'success',
            'reference' => $payload['reference'] ?? uniqid('sys_'),
+            'provider' => 'system',
            'data' => $payload,
        ];
    }

    public function handleWebhook(array $payload): array
    {
        // no-op for local system provider
        return [
            'handled' => true,
            'payload' => $payload,
        ];
    }
}
