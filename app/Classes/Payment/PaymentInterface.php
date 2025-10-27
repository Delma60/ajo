<?php

namespace App\Classes\Payment;

interface PaymentInterface
{
    /**
     * Initiate a charge. Returns an array with provider response details.
     *
     * @param array $payload
     * @return array
     */
    public function charge($method, array $payload): array;
    public function deposit($method, array $payload): array;

    /**
     * Handle webhook/callback payload
     * @param array $payload
     * @return array
     */
    public function handleWebhook(array $payload): array;
    public function transfer(array $payload): array;
    public function verifyBankAccount(array $payload): array;
    public function listBanks():array;

    public function createCustomer(array $payload): array;
    // public function createCustomer(array $payload): array;
    public function verifyCardPayment(array $payload):mixed;
    public function generateVirtualAccount(array $payload): array;


}

