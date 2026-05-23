<?php

namespace App\Classes\Payment;

use App\Models\Provider;
use Illuminate\Http\Client\Factory as HttpClient;

class FlutterwaveProvider implements PaymentInterface
{
    protected PaymentBase|null $gateway = null;

    public function __construct(Provider $provider, ?HttpClient $http = null)
    {
        // Route to the correct API version implementation
        $this->gateway = match ($provider->version) {
            'v3' => new FlutterwaveV3Provider($provider, $http),
            'v4' => new FlutterwaveV4Provider($provider, $http),
            default => throw new \RuntimeException("Unsupported Flutterwave API version: {$provider->version}"),
        };
    }

    // Delegate all PaymentInterface methods to the instantiated gateway

    public function charge($method, array $payload): array
    {
        return $this->gateway->charge($method, $payload);
    }

    public function deposit($method, array $payload): array
    {
        return $this->gateway->deposit($method, $payload);
    }

    public function handleWebhook(array $payload): array
    {
        return $this->gateway->handleWebhook($payload);
    }

    public function transfer(array $payload): array
    {
        return $this->gateway->transfer($payload);
    }

    public function verifyBankAccount(array $payload): array
    {
        return $this->gateway->verifyBankAccount($payload);
    }

    public function listBanks(): array
    {
        return $this->gateway->listBanks();
    }

    public function createCustomer(array $payload): array
    {
        return $this->gateway->createCustomer($payload);
    }

    public function verifyCardPayment(array $payload): mixed
    {
        return $this->gateway->verifyCardPayment($payload);
    }

    public function generateVirtualAccount(array $payload): array
    {
        return $this->gateway->generateVirtualAccount($payload);
    }

    /**
     * Magic method to catch any other specific methods (like driver(), cardDriver())
     * that might be called on the provider but aren't strictly in the interface.
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this->gateway, $name)) {
            return $this->gateway->{$name}(...$arguments);
        }

        throw new \BadMethodCallException("Method {$name} does not exist on the active Flutterwave gateway.");
    }
}