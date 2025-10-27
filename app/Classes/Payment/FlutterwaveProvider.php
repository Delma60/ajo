<?php

namespace App\Classes\Payment;

use App\Classes\Encryptor;
use App\Classes\Payment\Drivers\CardDriver;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualBank;
use BadMethodCallException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class FlutterwaveProvider extends PaymentBase
{
    protected $name = "flutterwave";

    protected function authorize()
    {
        $response = Http::asForm() // sends data as application/x-www-form-urlencoded
            ->post('https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token', [
                'client_id' => env("FLW_CLIENT_ID","c21efac0-e1ff-4ae6-8da4-002f45c4de56"),
                'client_secret' => env("FLW_SECRET_KEY","t4NhCi2sOxAdgAJK9t06BgfII9ihfFk6"),
                'grant_type' => 'client_credentials',
            ]);

        // You can return the token or full response
        if ($response->successful()) {
            return $response->json(); // contains access_token, token_type, expires_in, etc.
        }

        throw new \RuntimeException('Authorization failed: ' . $response->body());
    }

    protected function createCharge(array $payload):mixed{
        $res = $this->post("/charges", $payload);
        if(!$res['ok']) return [];
        return array_merge($res['raw']['data'], ["status" => "success", "save_to_db" => false]);
    }

    public function createCustomer(array $payload): array
    {
        $names = explode(" ", $payload['name']);
        $phone = preg_replace('/^0/', '', $payload['phone']);

        Log::info([
            "name" => [
                "first" => $names[0],
                "last" => end($names)
            ],
            "email" => $payload['email'],
            "phone" => [
                "country_code" => "234",
                "number" =>   $phone
            ]

        ]);

        $response = $this->post("/customers", [
            "name" => [
                "first" => $names[0],
                "last" => end($names)
            ],
            "email" => $payload['email'],
            "phone" => [
                "country_code" => "1",
                "number" => $phone
            ]

        ]);

        if(!($response['ok'] ?? false) && $response['status'] !== 409){
            return ["error" => "no res"];
        }

        return [
            "customer_id" => $response['raw']['data']['id']
        ];
    }

    function driver($driver="card"):mixed {
        return match($driver){
            "card" => new CardDriver($this),
            default => throw new InvalidArgumentException("Unknown driver: {$driver}"),
        };
    }

    public function cardDriver(array $payload){
        $method = $this->createCardMethod($payload);
        $charge = $this->createCharge([
            "payment_method_id" => $method['id'],
            "customer_id" => $payload['customer_id'],
            "amount" => $payload['amount'],
            "reference" => $payload['reference'],
            "currency" => "NGN",
            "authorization" => [
                "type" => "otp"
            ],
        ]);

        return $charge;
    }

    protected function availableDrivers(): array
    {
        return [
            "card"
        ];
    }

    protected function createCardMethod(array $payload){
        $card = $payload['card'];
        $key = env("FLW_ENCRYPTION_KEY");
        $nonce = $card['nonce'];
        $encrypted_card_number = Encryptor::encryptAES($card['card_number'], $key, $nonce);
        $encrypted_expiry_month = Encryptor::encryptAES($card['exp_month'], $key, $nonce);
        $encrypted_expiry_year = Encryptor::encryptAES($card['exp_year'], $key, $nonce);

        $res = $this->post("/payment-methods", [
            "type" => 'card',
            "card" => [
                'encrypted_card_number' => $encrypted_card_number,
                'encrypted_expiry_month' => $encrypted_expiry_month,
                'encrypted_expiry_year' => $encrypted_expiry_year,
                "nonce" => $card['nonce'],
            ]
        ]);

        if(!$res['ok']){
            Log::info($res);
            throw new RuntimeException("Error occurred creating card method");
        }

        return $res['raw']['data'];
    }

    /**
     * Handle incoming webhook/callback from Flutterwave and normalize it.
     *
     * @param array $payload
     * @return array
     */
    public function handleWebhook(array $payload): array
    {
        $data = $payload['data'] ?? $payload;

        // provider webhook type is usually in top-level 'type' (e.g. 'charge.completed')
        $providerWebhookType = $payload['type'] ?? $payload['event'] ?? null;

        // status mapping: Flutterwave may use 'succeeded' or 'success'
        $rawStatus = strtolower((string) ($data['status'] ?? ''));
        if (in_array($rawStatus, ['succeeded', 'successful', 'success', 'paid', 'completed'])) {
            $status = Transaction::STATUS_SUCCESS;
        } elseif (in_array($rawStatus, ['failed', 'error', 'cancelled'])) {
            $status = Transaction::STATUS_FAILED;
        } else {
            $status = $data['status'] ?? Transaction::STATUS_PENDING;
        }
        $paymentType = $data['payment_type'] ?? null;
        $customer = User::whereEmail($data['customer']['email'])->first();
        if($paymentType == "bank_transfer"){
            $vb = VirtualBank::whereUserId($customer->id)->get();
            foreach ($vb as $bank) {
                $bank->delete();
            }
        }

        // Common identifiers
        $reference = $data['reference'] ?? ($data['tx_ref'] ?? ($data['flw_ref'] ?? ($payload['reference'] ?? null)));
        $providerReference = $data['id'] ?? ($data['flw_ref'] ?? null);

        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;

        // pass through useful nested info
        // $customer = $data['customer'] ?? null;
        $processorResponse = $data['processor_response'] ?? ($data['response'] ?? null);

        return [
            'handled' => true,
            'status' => $status,
            'reference' => $reference,
            'provider_reference' => $providerReference,
            'amount' => $amount,
            'currency' => $currency,
            'provider' => 'flutterwave',
            'provider_webhook_type' => $providerWebhookType ?? 'charge.completed',
            'customer' => $customer->toArray(),
            'processor_response' => $processorResponse,
            'raw' => $payload,
        ];
    }

    /**
     * Base URL (sandbox by default). You may override via config/env if needed.
     *
     * @return string
     */
    public function baseUrl(): string
    {
        return env("FLW_URL",'https://developersandbox-api.flutterwave.com');
    }

    /**
     * Default headers for Flutterwave requests.
     *
     * @return array
     */
    protected function header(): array
    {
        $traceId = Str::uuid()->toString();
        $idempotencyKey = Str::uuid()->toString();
        // $authorization = $this->authorize();
        $access_token = env("FLW_SECRET_KEY"); //$authorization['access_token'];
        // Log::info($access_token);
        return [
            'Authorization' => "Bearer $access_token",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Trace-Id' => $traceId,
            'X-Idempotency-Key' => $idempotencyKey,
            "X-Scenario-Key" => "scenario:auth_pin&issuer:approved"
        ];
    }

    /**
     * Convert generic payload into Flutterwave request body.
     *
     * @param array $payload
     * @return array
     */
    protected function formatRequest(array $payload): array
    {
        // tx_ref: prefer provided reference, otherwise generate one
        $txRef = $payload['reference'] ?? uniqid('flw_');

        // Customer object shape Flutterwave expects
        $customer = $payload['customer'] ?? [
            'email' => $payload['email'] ?? null,
            'phonenumber' => $payload['phone'] ?? null,
            'name' => $payload['firstname'] ?? ($payload['name'] ?? null),
        ];

        return [
            'tx_ref' => $txRef,
            'amount' => $payload['amount'],
            'currency' => $payload['currency'] ?? 'NGN',
            'redirect_url' => $payload['redirect'] ?? ($payload['redirect_url'] ?? null),
            'payment_options' => $payload['payment_options'] ?? 'card,ussd',
            'customer' => $customer,
            'meta' => $payload['meta'] ?? [],
            'customizations' => $payload['customizations'] ?? [],
        ];
    }

    /**
     * Normalize the response returned by PaymentBase::post() into a consistent app shape.
     *
     * @param array $response  The array returned from PaymentBase::post() -> ['ok','status','raw']
     * @return array
     */
    protected function formatResponse(array $response): array
    {
        $raw = $response['raw'] ?? [];

        // Flutterwave typical shape: ['status' => 'success', 'data' => [...]]
        $status = $raw['status'] ?? ($response['ok'] ? 'success' : 'failed');
        $data = $raw['data'] ?? $raw;

        $reference = $data['tx_ref'] ?? ($data['flw_ref'] ?? ($data['reference'] ?? null));
        $redirect = $data['link'] ?? ($data['payment_link'] ?? ($data['meta']['redirect_url'] ?? null));
        // sometimes Flutterwave returns a nested 'link' or in 'data'

        return [
            'ok' => $response['ok'] ?? false,
            'http_status' => $response['status'] ?? null,
            'status' => $status,
            'reference' => $reference,
            'provider' => 'flutterwave',
            'redirect' => $redirect,
            'raw' => $raw,
        ];
    }

    /**
     * Card payment helper stub. Implement card-specific flows here.
     *
     * @param array $payload
     * @return array
     * @throws BadMethodCallException
     */
    protected function cardPayment(array $payload = []): array
    {
        throw new BadMethodCallException('cardPayment not implemented for FlutterwaveProvider');
    }

    /**
     * Generate virtual bank account stub.
     *
     * @param array $payload
     * @return array
     * @throws BadMethodCallException
     */
    protected function virtualAccount(array $payload): array
    {
        $reference = Str::uuid()->toString();
        $res = $this->post("/virtual-account-numbers",[
            "reference" => $reference,
            "customer_id" => $payload['customer_id'],
            "currency" => "NGN",
            "account_type" => "dynamic",
            // "bvn" => "22366143487",
            "email" => $payload['user']['email'],
            "amount" => $payload['amount']
        ]);
        Log::info(["generate virtual account" => $res]);
        if(!($res['ok'] ?? false)){
            throw new RuntimeException("Error generating virtual account");
        }
        $data = $res['raw']['data'];

        return [
            'user_id' => $payload['user']['id'],
            'status' => 'active',
            'provider' => 'flutterwave',
            'account_number' => $data['account_number'] ??'',
            'bank_name' => $data['bank_name'] ?? '',
            'reference' => $reference,
            'meta' => $data,

        ];
    }

    /**
     * USSD payment helper stub.
     *
     * @param array $payload
     * @return array
     * @throws BadMethodCallException
     */
    protected function ussdPayment(array $payload = []): array
    {
        throw new BadMethodCallException('ussdPayment not implemented for FlutterwaveProvider');
    }

    protected function cardPaymentVerification(array $payload): mixed
    {

        // Accept charge identifier in several common forms: charge_id, provider_reference (flw_ref), tx_ref or reference
        $chargeId = $payload['charge_id'];
        Log::info(["charge_id" =>$chargeId]);


        if (empty($chargeId)) {
            throw new InvalidArgumentException('charge identifier (charge_id/provider_reference/tx_ref/reference) is required for verification');
        }

        // Determine auth type: pin or otp (otp typically is passed as 'otp')
        $authType = 'pin';
        if (!empty($payload['otp'])) {
            $authType = 'otp';
        }

        $body = [
            'authorization' => [
                'type' => $authType,
            ]
        ];

        // If pin provided, encrypt it as Flutterwave expects (AES-GCM base64 combined)
        if (!empty($payload['pin'])) {
            $key = env('FLW_ENCRYPTION_KEY');
            $nonce = Encryptor::nonce();
            $encrypted_pin = Encryptor::encryptAES($payload['pin'], $key, $nonce);

            $body['authorization']['pin'] = [
                'nonce' => $nonce,
                'encrypted_pin' => $encrypted_pin,
            ];
            $body['authorization']['type'] = 'pin';
        }

        // If otp provided, attach it
        if (!empty($payload['otp'])) {
            $body['authorization']['otp'] = $payload['otp'];
            $body['authorization']['type'] = 'otp';
        }

        $res = $this->put("/charges/{$chargeId}", $body);


        // Normalize response similar to formatResponse
        if (!($res['ok'] ?? false)) {
            return [
                'ok' => false,
                'status' => $res['status'] ?? null,
                'provider' => 'flutterwave',
                'raw' => $res['raw'] ?? [],
            ];
        }

        $data = $res['raw']['data'] ?? $res['raw'];
        $reference = $data['tx_ref'] ?? ($data['flw_ref'] ?? ($data['reference'] ?? null));

        return [
            'ok' => true,
            'status' => $res['raw']['status'] ?? 'success',
            'provider' => 'flutterwave',
            'reference' => $reference,
            'provider_reference' => $data['flw_ref'] ?? $data['id'] ?? null,
            'raw' => $res['raw'] ?? [],
        ];
    }

    public function verifyBankAccount(array $payload):array{
        $res = $this->post("/banks/account-resolve", [
            "account" => [
                "code" => $payload['code'],
                "number" => $payload['number'],
            ],
            "currency" => "NGN"
        ]);
        return $res['raw'];
    }
    protected function handleTransfer(array $payload):array{
        $res= $this->post("/direct-transfers", [
            "action" => "instant",
            "type" => 'bank',
            "reference" => $payload['reference'],
            "payment_instruction" => [
                "amount" => [
                    "value" => $payload['amount_to_be_paid'],
                    "applies_to" => "destination_currency",
                ],
                "source_currency" => "NGN",
                "destination_currency" => "NGN",
                "recipient" => [
                    "bank" => [
                        "code" => $payload['bank']['meta']['code'] ?? '044',
                        "account_number" => $payload['bank']['account_number']
                    ]
                ]
            ]
        ]);
        if(!$res) return [];

        $data = $res['raw']['data'];

        return [
            "provider" => $this->name,
            "provider_reference" => $data['id'],
            "meta" => $data,
            "reference" => $data['reference']
        ];
    }

    function listBanks():array{

        return Cache::remember('#flutterwave_banks', 86400, function() {
             $res = $this->get("/banks", [
                "country" => "NG"
            ]);

            if(!($res['ok'] ?? false)){
                return [];
            }

            return $res['raw'];
        });
    }
}
