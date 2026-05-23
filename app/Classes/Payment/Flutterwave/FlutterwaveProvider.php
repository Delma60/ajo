<?php

namespace App\Classes\Payment\Flutterwave;

use App\Classes\Payment\PaymentInterface;
use App\Models\Provider;
use Illuminate\Http\Client\Factory as HttpClient;
use RuntimeException;

/**
 * FlutterwaveProvider (Proxy / Router)
 *
 * Reads the `version` column on the Provider model and delegates every call to
 * the correct concrete implementation (V3 or V4).  Adding a new API version only
 * requires creating a new class and adding a match arm here.
 */
class FlutterwaveProvider implements PaymentInterface
{
    protected PaymentInterface $gateway;

    public function __construct(?Provider $provider = null, ?HttpClient $http = null)
    {
        // When no DB row exists (e.g. legacy code paths), read version from env
        $version = $provider?->version ?? env('FLW_API_VERSION', 'v3');

        $this->gateway = match ($version) {
            'v3'    => new FlutterwaveV3Provider($provider, $http),
            'v4'    => new FlutterwaveV4Provider($provider, $http),
            default => throw new RuntimeException("Unsupported Flutterwave API version: [{$version}]"),
        };
    }

    // -------------------------------------------------------------------------
    // PaymentInterface — pure delegation
    // -------------------------------------------------------------------------

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
     * Forward any version-specific method calls (e.g. cardDriver, driver)
     * directly to the active gateway so callers don't need to unwrap the proxy.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (method_exists($this->gateway, $name)) {
            return $this->gateway->{$name}(...$arguments);
        }

        throw new \BadMethodCallException(
            "Method [{$name}] does not exist on " . get_class($this->gateway)
        );
    }
}