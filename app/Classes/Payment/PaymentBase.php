<?php

namespace App\Classes\Payment;

use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * PaymentBase
 *
 * Provides shared HTTP helpers (post/put/get) and soft default implementations
 * for every PaymentInterface method.  Concrete providers override what they need;
 * they no longer have to implement abstract stubs they will never use.
 */
abstract class PaymentBase implements PaymentInterface
{
    protected HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? Http::getFacadeRoot();
    }

    // -------------------------------------------------------------------------
    // Subclasses MUST declare these two
    // -------------------------------------------------------------------------

    abstract public function baseUrl(): string;

    /** Default headers sent with every request (e.g. Authorization). */
    abstract protected function header(): array;

    // -------------------------------------------------------------------------
    // Shared HTTP helpers
    // -------------------------------------------------------------------------

    protected function post(string $path, array $payload = [], array $extra = []): array
    {
        if ($path === '') {
            throw new InvalidArgumentException('path is required for post()');
        }

        $url      = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');
        $headers  = array_merge($this->header(), $extra);
        $response = $this->http->withHeaders($headers)->post($url, $payload);

        return [
            'ok'     => $response->successful(),
            'status' => $response->status(),
            'raw'    => $this->safeJson($response),
        ];
    }

    protected function put(string $path, array $payload = [], array $extra = []): array
    {
        if ($path === '') {
            throw new InvalidArgumentException('path is required for put()');
        }

        $url      = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');
        $headers  = array_merge($this->header(), $extra);
        $response = $this->http->withHeaders($headers)->put($url, $payload);

        return [
            'ok'     => $response->successful(),
            'status' => $response->status(),
            'raw'    => $this->safeJson($response),
        ];
    }

    protected function get(string $path, array $query = [], array $extra = []): array
    {
        $url      = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');
        $headers  = array_merge($this->header(), $extra);
        $response = $this->http->withHeaders($headers)->get($url, $query);

        return [
            'ok'     => $response->successful(),
            'status' => $response->status(),
            'raw'    => $this->safeJson($response),
        ];
    }

    protected function safeJson(Response $response): array
    {
        try {
            $json = $response->json();
            return is_array($json) ? $json : ['body' => $response->body()];
        } catch (\Throwable $e) {
            return ['body' => $response->body()];
        }
    }

    // -------------------------------------------------------------------------
    // PaymentInterface — soft defaults (throw clearly if a provider omits them)
    // -------------------------------------------------------------------------

    public function charge($method, array $payload): array
    {
        throw new \BadMethodCallException(static::class . '::charge() is not implemented.');
    }

    public function deposit($method, array $payload): array
    {
        // Default: alias charge
        return $this->charge($method, $payload);
    }

    public function handleWebhook(array $payload): array
    {
        throw new \BadMethodCallException(static::class . '::handleWebhook() is not implemented.');
    }

    public function transfer(array $payload): array
    {
        throw new \BadMethodCallException(static::class . '::transfer() is not implemented.');
    }

    public function verifyBankAccount(array $payload): array
    {
        throw new \BadMethodCallException(static::class . '::verifyBankAccount() is not implemented.');
    }

    public function listBanks(): array
    {
        throw new \BadMethodCallException(static::class . '::listBanks() is not implemented.');
    }

    public function createCustomer(array $payload): array
    {
        throw new \BadMethodCallException(static::class . '::createCustomer() is not implemented.');
    }

    public function verifyCardPayment(array $payload): mixed
    {
        throw new \BadMethodCallException(static::class . '::verifyCardPayment() is not implemented.');
    }

    public function generateVirtualAccount(array $payload): array
    {
        throw new \BadMethodCallException(static::class . '::generateVirtualAccount() is not implemented.');
    }
}