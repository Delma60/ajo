<?php

namespace App\Classes\Payment;

use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

abstract class PaymentBase implements PaymentInterface
{
    protected HttpClient $http;

    /**
     * Optionally pass a custom HTTP client (useful for testing).
     *
     * @param HttpClient|null $http
     */
    public function __construct(?HttpClient $http = null)
    {
        // Default to Laravel's Http client
        $this->http = $http ?? Http::getFacadeRoot();
    }


    /**
     * Make a POST request to provider.
     *
     * @param string $path  Path relative to baseUrl()
     * @param array  $payload
     * @param array  $extraHeaders
     * @return array Normalized result: ['ok' => bool, 'status' => int, 'raw' => array]
     */
    protected function post(string $path, array $payload = [], array $extraHeaders = []): array
    {
        if ($path === '') {
            throw new InvalidArgumentException('path is required for post()');
        }


        $url = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');
        $headers = array_merge($this->header(), $extraHeaders);

        /** @var Response $response */

        $response = $this->http->withHeaders($headers)->post($url, $payload);

        $decoded = $this->safeJson($response);

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'raw' => $decoded,
        ];
    }

    protected function put(string $path, array $payload = [], array $extraHeaders = []): array
    {
        if ($path === '') {
            throw new InvalidArgumentException('path is required for post()');
        }

        $url = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');
        $headers = array_merge($this->header(), $extraHeaders);

        /** @var Response $response */
        $response = $this->http->withHeaders($headers)->put($url, $payload);

        $decoded = $this->safeJson($response);

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'raw' => $decoded,
        ];
    }

    /**
     * Make a GET request to provider.
     *
     * @param string $path
     * @param array  $query
     * @param array  $extraHeaders
     * @return array
     */
    protected function get(string $path, array $query = [], array $extraHeaders = []): array
    {
        $url = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');
        $headers = array_merge($this->header(), $extraHeaders);

        /** @var Response $response */
        $response = $this->http->withHeaders($headers)->get($url, $query);

        $decoded = $this->safeJson($response);

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'raw' => $decoded,
        ];
    }

    /**
     * Safely decode response JSON (fallback to raw body).
     *
     * @param Response $response
     * @return array
     */
    protected function safeJson(Response $response): array
    {
        try {
            $json = $response->json();
            return is_array($json) ? $json : ['body' => $response->body()];
        } catch (\Throwable $e) {
            return ['body' => $response->body()];
        }
    }

    abstract protected function handleTransfer(array $payload):array;

    public function transfer(array $payload): array
    {
        return $this->handleTransfer($payload);
    }


    //
    // ABSTRACT METHODS (providers MUST implement these)
    //

    /**
     * Base URL for the provider (e.g. sandbox/production).
     *
     * @return string
     */
    abstract public function baseUrl(): string;

    /**
     * Return default headers for provider requests (e.g. Authorization).
     *
     * @return array
     */
    abstract protected function header(): array;


    function deposit($method, array $payload):array{
        return $this->charge($method, $payload);
    }

    /**
     * Convert generic payload into provider-specific request payload.
     *
     * @param array $payload
     * @return array
     */
    abstract protected function formatRequest(array $payload): array;

    /**
     * Normalize provider response into app-friendly shape.
     *
     * @param array $response
     * @return array
     */
    abstract protected function formatResponse(array $response): array;

    /**
     * Initiate a charge. Providers must implement this (from PaymentInterface).
     *
     * @param array $payload
     * @return array
     */
    public function charge($method, array $payload):array {
        $availableDrivers = $this->availableDrivers();
        $payload['trackId'] = Str::uuid()->toString();
        if(!in_array($method, $availableDrivers)){
            throw new \InvalidArgumentException(
                sprintf(
                "Method '%s' not available for provider %s. Available: %s",
                $method,
                static::class,
                implode(', ', $availableDrivers)
            )
            );
        }
        $driver = $this->driver($method);
        $payment = $driver($payload);
        return array_merge($payment, ["reference" => $payload['reference'], "trackId" => $payload['trackId']]);
    }

    public function verifyCardPayment(array $payload): mixed
    {
        return $this->cardPaymentVerification($payload);
    }

    public function generateVirtualAccount(array $payload): array {
        return $this->virtualAccount($payload);
    }



    /**
     * Handle a webhook/callback. Providers must implement this (from PaymentInterface).
     *
     * @param array $payload
     * @return array
     */
    abstract public function handleWebhook(array $payload): array;


    abstract protected function driver($driver):mixed;
    abstract protected function availableDrivers():array;
    abstract protected function createCharge(array $payload):mixed;
    abstract protected function cardPaymentVerification(array $payload):mixed;
    abstract protected function virtualAccount(array $payload):mixed;
}
