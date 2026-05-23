<?php

namespace App\Classes\Payment\Flutterwave;

use App\Classes\Encryptor;
use App\Classes\Payment\Drivers\CardDriver;
use App\Classes\Payment\PaymentBase;
use App\Classes\Payment\PaymentInterface;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use BadMethodCallException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Http\Client\Factory as HttpClient;

class FlutterwaveV4Provider extends PaymentBase
{
    protected $name = "flutterwave_v4";
    protected Provider $provider;

    public function __construct(Provider $provider, ?HttpClient $http = null)
    {
        parent::__construct($http);
        $this->provider = $provider;
    }

    protected function authorize()
    {
        $response = Http::asForm()
            ->post('https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token', [
                'client_id' => $this->provider->public_key,
                'client_secret' => $this->provider->secret_key,
                'grant_type' => 'client_credentials',
            ]);

        if ($response->successful()) {
            return $response->json(); 
        }

        throw new RuntimeException('Authorization failed: ' . $response->body());
    }

    // Map interface methods directly to your logic...
    public function charge($method, array $payload): array { /* Implement using $this->driver($method) */ return []; }
    public function deposit($method, array $payload): array { return []; }
    
    // Rename handleTransfer to transfer to match Interface
    public function transfer(array $payload): array { return $this->handleTransfer($payload); }
    
    // Rename cardPaymentVerification to verifyCardPayment to match Interface
    public function verifyCardPayment(array $payload): mixed { return $this->cardPaymentVerification($payload); }

    // (Keep the rest of your exact V4 methods here: createCustomer, generateVirtualAccount, listBanks, etc.)
    // ... [Insert all your existing methods from the original FlutterwaveProvider here] ...

    public function baseUrl(): string
    {
        return env("FLW_V4_URL", 'https://developersandbox-api.flutterwave.com');
    }

    protected function header(): array
    {
        $traceId = Str::uuid()->toString();
        $idempotencyKey = Str::uuid()->toString();
        $access_token = env("FLW_SECRET_KEY"); 
        
        return [
            'Authorization' => "Bearer $access_token",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Trace-Id' => $traceId,
            'X-Idempotency-Key' => $idempotencyKey,
            "X-Scenario-Key" => "scenario:auth_pin&issuer:approved"
        ];
    }
}