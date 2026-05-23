<?php

namespace App\Classes\Payment\Flutterwave;

use App\Classes\Payment\PaymentBase;
use App\Classes\Payment\PaymentInterface;
use App\Models\Provider;
use App\Models\Transaction;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Support\Facades\Log;

class FlutterwaveV3Provider extends PaymentBase implements PaymentInterface
{
    protected $name = "flutterwave_v3";
    protected Provider $provider;

    public function __construct(Provider $provider, ?HttpClient $http = null)
    {
        parent::__construct($http);
        $this->provider = $provider;
    }

    public function baseUrl(): string
    {
        // Standard V3 base URL
        return env("FLW_V3_URL", 'https://api.flutterwave.com/v3');
    }

    protected function header(): array
    {
        // V3 uses the Secret Key directly as the Bearer token
        return [
            'Authorization' => "Bearer " . $this->provider->secret_key,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function charge($method, array $payload): array
    {
        // V3 specific charge routing (e.g., direct to /charges?type=card)
        return [];
    }

    public function deposit($method, array $payload): array
    {
        return [];
    }

    public function handleWebhook(array $payload): array
    {
        $data = $payload['data'] ?? $payload;
        $status = strtolower((string) ($data['status'] ?? ''));
        
        if (in_array($status, ['successful', 'success'])) {
            $mappedStatus = Transaction::STATUS_SUCCESS;
        } elseif (in_array($status, ['failed'])) {
            $mappedStatus = Transaction::STATUS_FAILED;
        } else {
            $mappedStatus = Transaction::STATUS_PENDING;
        }

        return [
            'handled' => true,
            'status' => $mappedStatus,
            'reference' => $data['tx_ref'] ?? null,
            'provider_reference' => $data['id'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'provider' => 'flutterwave',
        ];
    }

    public function transfer(array $payload): array
    {
        $res = $this->post("/transfers", [
            "account_bank" => $payload['bank']['meta']['code'],
            "account_number" => $payload['bank']['account_number'],
            "amount" => $payload['amount_to_be_paid'],
            "currency" => "NGN",
            "reference" => $payload['reference'],
            "narration" => "Payout"
        ]);
        
        if (!($res['ok'] ?? false)) return [];

        $data = $res['raw']['data'];

        return [
            "provider" => "flutterwave",
            "provider_reference" => $data['id'],
            "meta" => $data,
            "reference" => $data['reference']
        ];
    }

    public function verifyBankAccount(array $payload): array
    {
        $res = $this->post("/accounts/resolve", [
            "account_number" => $payload['number'],
            "account_bank" => $payload['code'] // Note: V3 uses account_bank, not account_code
        ]);
        
        return $res['raw'];
    }

    public function listBanks(): array
    {
        $res = $this->get("/banks/NG"); // V3 endpoint for Nigerian banks
        return $res['raw'] ?? [];
    }

    public function createCustomer(array $payload): array
    {
        // V3 doesn't typically require pre-creating customers for charges, 
        // customer info is passed directly in the payload.
        return ["customer_id" => null];
    }

    public function verifyCardPayment(array $payload): mixed
    {
        // V3 uses /transactions/{id}/verify
        $transactionId = $payload['charge_id'];
        $res = $this->get("/transactions/{$transactionId}/verify");
        
        return $res['raw'] ?? [];
    }

    public function generateVirtualAccount(array $payload): array
    {
        $res = $this->post("/virtual-account-numbers", [
            "email" => $payload['user']['email'],
            "is_permanent" => false,
            "tx_ref" => $payload['reference'] ?? Str::uuid()->toString(),
            "amount" => $payload['amount']
        ]);

        return $res['raw'] ?? [];
    }
}