<?php

namespace App\Classes\Payment\Flutterwave;

use App\Classes\Encryptor;
use App\Classes\Payment\PaymentBase;
use App\Classes\Payment\PaymentInterface;
use App\Classes\Payment\Drivers\CardDriver;
use App\Models\Provider;
use App\Models\Transaction;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * FlutterwaveV4Provider
 *
 * Implements Flutterwave's developer-sandbox / V4 API.
 * Base URL  : https://developersandbox-api.flutterwave.com  (override via FLW_V4_URL)
 * Auth      : OAuth2 client_credentials  OR  static Bearer token (FLW_SECRET_KEY)
 * Docs      : https://developer.flutterwave.com/docs (V4 sandbox)
 *
 * Covered endpoints
 * -----------------
 *  POST   /customers                   create customer
 *  POST   /payment-methods             tokenise a card (encrypted)
 *  POST   /charges                     initiate charge (card, bank-transfer, …)
 *  PUT    /charges/{id}                authorise charge (PIN / OTP step)
 *  GET    /charges/{id}                fetch charge status
 *  POST   /transfers                   bank transfer / payout
 *  GET    /banks                       list banks (query: country=NG)
 *  POST   /accounts/resolve            verify bank account
 *  POST   /virtual-account-numbers     generate a dynamic virtual account
 */
class FlutterwaveV4Provider extends PaymentBase implements PaymentInterface
{
    protected string  $name = 'flutterwave_v4';
    protected ?Provider $provider;

    /** Cached OAuth token array: ['access_token'=>..., 'expires_at'=> Carbon] */
    private ?array $tokenCache = null;

    public function __construct(?Provider $provider = null, ?HttpClient $http = null)
    {
        parent::__construct($http);
        $this->provider = $provider;
    }

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    public function baseUrl(): string
    {
        return rtrim(env('FLW_V4_URL', 'https://developersandbox-api.flutterwave.com'), '/');
    }

    protected function header(): array
    {
        return [
            'Authorization'     => 'Bearer ' . $this->resolveAccessToken(),
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
            'X-Trace-Id'        => Str::uuid()->toString(),
            'X-Idempotency-Key' => Str::uuid()->toString(),
            // Sandbox scenario header — remove in production
            'X-Scenario-Key'    => env('FLW_SCENARIO_KEY', 'scenario:auth_pin&issuer:approved'),
        ];
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    /**
     * Resolve Bearer token.
     *
     * - If FLW_USE_OAUTH=true: fetch client_credentials token (cached).
     * - Otherwise fall back to the static secret key (simple and sufficient for V4 sandbox).
     */
    protected function resolveAccessToken(): string
    {
        if (!env('FLW_USE_OAUTH', false)) {
            return $this->provider?->secret_key ?? env('FLW_SECRET_KEY', '');
        }

        return $this->fetchOAuthToken();
    }

    protected function fetchOAuthToken(): string
    {
        $cacheKey = 'flw_v4_oauth_token_' . md5(
            ($this->provider?->public_key ?? '') . ($this->provider?->secret_key ?? '')
        );

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = Http::asForm()->post(
            'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token',
            [
                'client_id'     => $this->provider?->public_key ?? env('FLW_CLIENT_ID'),
                'client_secret' => $this->provider?->secret_key ?? env('FLW_SECRET_KEY'),
                'grant_type'    => 'client_credentials',
            ]
        );

        if (!$response->successful()) {
            throw new RuntimeException('[FLW V4] OAuth token fetch failed: ' . $response->body());
        }

        $json  = $response->json();
        $token = $json['access_token'];
        $ttl   = max(60, (int) ($json['expires_in'] ?? 3600) - 60); // refresh 60 s early

        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    // -------------------------------------------------------------------------
    // Customers  (POST /customers)
    // -------------------------------------------------------------------------

    public function createCustomer(array $payload): array
    {
        $names     = explode(' ', trim($payload['name'] ?? ''));
        $firstName = $names[0] ?? '';
        $lastName  = count($names) > 1 ? implode(' ', array_slice($names, 1)) : $firstName;
        $phone     = preg_replace('/^0/', '', $payload['phone'] ?? '');

        $res = $this->post('/customers', [
            'name'  => [
                'first' => $firstName,
                'last'  => $lastName,
            ],
            'email' => $payload['email'],
            'phone' => [
                'country_code' => $payload['country_code'] ?? '234',
                'number'       => $phone,
            ],
        ]);

        // 409 Conflict means customer already exists — treat as success
        $ok = ($res['ok'] ?? false) || ($res['status'] ?? 0) === 409;

        if (!$ok) {
            Log::error('[FLW V4] createCustomer failed', ['response' => $res]);
            throw new RuntimeException('[FLW V4] Failed to create customer: ' . ($res['raw']['message'] ?? 'unknown'));
        }

        return [
            'customer_id' => $res['raw']['data']['id'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Card tokenisation  (POST /payment-methods)
    // -------------------------------------------------------------------------

    /**
     * Encrypt card details and create a payment method token.
     *
     * $payload must include a 'card' key with:
     *   card_number, exp_month, exp_year, nonce
     *
     * Returns the full payment-method object from Flutterwave.
     */
    public function createCardMethod(array $payload): array
    {
        $card  = $payload['card'];
        $key   = $this->provider?->encryption_key ?? env('FLW_ENCRYPTION_KEY');
        $nonce = $card['nonce'] ?? Encryptor::nonce();

        $res = $this->post('/payment-methods', [
            'type' => 'card',
            'card' => [
                'encrypted_card_number'  => Encryptor::encryptAES($card['card_number'], $key, $nonce),
                'encrypted_expiry_month' => Encryptor::encryptAES($card['exp_month'],   $key, $nonce),
                'encrypted_expiry_year'  => Encryptor::encryptAES($card['exp_year'],    $key, $nonce),
                'nonce'                  => $nonce,
            ],
        ]);

        if (!($res['ok'] ?? false)) {
            Log::error('[FLW V4] createCardMethod failed', ['response' => $res]);
            throw new RuntimeException('[FLW V4] Failed to create card payment method: ' . ($res['raw']['message'] ?? 'unknown'));
        }

        return $res['raw']['data'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Charges  (POST /charges)
    // -------------------------------------------------------------------------

    /**
     * Initiate a charge.
     *
     * $method: 'card' | 'banktransfer' | 'ussd'
     *
     * For card:  requires payload.card_id (BankCard model id) + payload.customer_id
     *            OR  pass payload.card directly with raw details.
     */
    public function charge($method, array $payload): array
    {
        $method = strtolower($method);

        return match ($method) {
            'card'        => $this->chargeCard($payload),
            'banktransfer'=> $this->chargeBankTransfer($payload),
            default       => throw new InvalidArgumentException("[FLW V4] Unknown charge method: [{$method}]"),
        };
    }

    protected function chargeCard(array $payload): array
    {
        // Create the payment method first if not already tokenised
        $pmId = $payload['payment_method_id'] ?? null;
        if (!$pmId) {
            $pm   = $this->createCardMethod($payload);
            $pmId = $pm['id'];
        }

        $res = $this->post('/charges', [
            'payment_method_id' => $pmId,
            'customer_id'       => $payload['customer_id'],
            'amount'            => $payload['amount'],
            'reference'         => $payload['reference'] ?? Str::uuid()->toString(),
            'currency'          => $payload['currency'] ?? 'NGN',
            'authorization'     => [
                'type' => $payload['authorization']['type'] ?? 'otp',
            ],
        ]);

        if (!($res['ok'] ?? false)) {
            Log::error('[FLW V4] chargeCard failed', ['response' => $res]);
            return [
                'ok'      => false,
                'message' => $res['raw']['message'] ?? 'Card charge failed',
                'raw'     => $res['raw'] ?? [],
            ];
        }

        $data = $res['raw']['data'] ?? [];

        return array_merge($data, [
            'ok'       => true,
            'status'   => 'success',
            'provider' => $this->name,
        ]);
    }

    protected function chargeBankTransfer(array $payload): array
    {
        $res = $this->post('/charges', [
            'type'      => 'banktransfer',
            'amount'    => $payload['amount'],
            'reference' => $payload['reference'] ?? Str::uuid()->toString(),
            'currency'  => $payload['currency'] ?? 'NGN',
            'email'     => $payload['email'] ?? $payload['user']['email'] ?? null,
        ]);

        return [
            'ok'      => $res['ok'] ?? false,
            'status'  => $res['raw']['status'] ?? null,
            'data'    => $res['raw']['data'] ?? [],
            'raw'     => $res['raw'],
        ];
    }

    public function deposit($method, array $payload): array
    {
        return $this->charge($method, $payload);
    }

    // -------------------------------------------------------------------------
    // Authorise charge (PIN / OTP)  (PUT /charges/{id})
    // -------------------------------------------------------------------------

    /**
     * Authorise a charge that requires PIN or OTP.
     *
     * @param array $payload  ['charge_id'=>..., 'pin'=>..., 'otp'=>...]
     */
    public function verifyCardPayment(array $payload): mixed
    {
        $chargeId = $payload['charge_id'] ?? null;

        if (empty($chargeId)) {
            throw new InvalidArgumentException('[FLW V4] charge_id is required for verifyCardPayment()');
        }

        $authType = !empty($payload['otp']) ? 'otp' : 'pin';
        $body     = ['authorization' => ['type' => $authType]];

        if (!empty($payload['pin'])) {
            $key   = $this->provider?->encryption_key ?? env('FLW_ENCRYPTION_KEY');
            $nonce = Encryptor::nonce();

            $body['authorization']['type'] = 'pin';
            $body['authorization']['pin']  = [
                'nonce'         => $nonce,
                'encrypted_pin' => Encryptor::encryptAES($payload['pin'], $key, $nonce),
            ];
        }

        if (!empty($payload['otp'])) {
            $body['authorization']['type'] = 'otp';
            $body['authorization']['otp']  = $payload['otp'];
        }

        $res = $this->put("/charges/{$chargeId}", $body);

        if (!($res['ok'] ?? false)) {
            return [
                'ok'      => false,
                'status'  => $res['status'] ?? null,
                'message' => $res['raw']['message'] ?? 'Authorisation failed',
                'raw'     => $res['raw'] ?? [],
            ];
        }

        $data = $res['raw']['data'] ?? $res['raw'];

        return [
            'ok'                 => true,
            'provider'           => $this->name,
            'status'             => $res['raw']['status'] ?? 'success',
            'reference'          => $data['tx_ref'] ?? ($data['reference'] ?? null),
            'provider_reference' => $data['flw_ref'] ?? ($data['id'] ?? null),
            'raw'                => $res['raw'],
        ];
    }

    // -------------------------------------------------------------------------
    // Card driver (used by CardDriver class)
    // -------------------------------------------------------------------------

    public function driver(string $driver = 'card'): mixed
    {
        return match ($driver) {
            'card'  => new CardDriver($this),
            default => throw new InvalidArgumentException("[FLW V4] Unknown driver: [{$driver}]"),
        };
    }

    public function cardDriver(array $payload): mixed
    {
        $pm     = $this->createCardMethod($payload);
        $charge = $this->post('/charges', [
            'payment_method_id' => $pm['id'],
            'customer_id'       => $payload['customer_id'],
            'amount'            => $payload['amount'],
            'reference'         => $payload['reference'] ?? Str::uuid()->toString(),
            'currency'          => $payload['currency'] ?? 'NGN',
            'authorization'     => ['type' => 'otp'],
        ]);

        if (!($charge['ok'] ?? false)) {
            return [];
        }

        return array_merge($charge['raw']['data'] ?? [], ['status' => 'success']);
    }

    // -------------------------------------------------------------------------
    // Transfers / payouts  (POST /transfers)
    // -------------------------------------------------------------------------

    public function transfer(array $payload): array
    {
        $reference = $payload['reference'] ?? Str::uuid()->toString();

        $res = $this->post('/transfers', [
            'account_bank'   => $payload['bank']['meta']['code'] ?? $payload['bank_code'] ?? null,
            'account_number' => $payload['bank']['account_number'] ?? $payload['account_number'] ?? null,
            'amount'         => $payload['amount_to_be_paid'] ?? $payload['amount'],
            'currency'       => $payload['currency'] ?? 'NGN',
            'reference'      => $reference,
            'narration'      => $payload['narration'] ?? 'Payout',
        ]);

        if (!($res['ok'] ?? false)) {
            Log::error('[FLW V4] transfer failed', ['response' => $res]);
            return [];
        }

        $data = $res['raw']['data'] ?? [];

        return [
            'provider'           => $this->name,
            'provider_reference' => $data['id'] ?? null,
            'reference'          => $data['reference'] ?? $reference,
            'status'             => $data['status'] ?? null,
            'meta'               => $data,
        ];
    }

    // -------------------------------------------------------------------------
    // Banks  (GET /banks?country=NG)
    // -------------------------------------------------------------------------

    public function listBanks(): array
    {
        return Cache::remember('flutterwave_v4_banks_NG', 86400, function () {
            $res = $this->get('/banks', ['country' => 'NG']);

            if (!($res['ok'] ?? false)) {
                Log::warning('[FLW V4] listBanks failed', ['response' => $res]);
                return [];
            }

            return $res['raw'];
        });
    }

    // -------------------------------------------------------------------------
    // Verify bank account  (POST /accounts/resolve)
    // -------------------------------------------------------------------------

    public function verifyBankAccount(array $payload): array
    {
        $res = $this->post('/accounts/resolve', [
            'account_number' => $payload['number'] ?? $payload['account_number'],
            'account_code'   => $payload['code']   ?? $payload['bank_code'],
        ]);

        return $res['raw'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Virtual account  (POST /virtual-account-numbers)
    // -------------------------------------------------------------------------

    public function generateVirtualAccount(array $payload): array
    {
        $reference  = $payload['reference'] ?? Str::uuid()->toString();
        $customerId = $payload['customer_id'] ?? null;

        $body = [
            'reference'    => $reference,
            'currency'     => $payload['currency'] ?? 'NGN',
            'account_type' => $payload['account_type'] ?? 'dynamic',
            'amount'       => $payload['amount'] ?? null,
            'email'        => $payload['user']['email'] ?? $payload['email'] ?? null,
        ];

        if ($customerId) {
            $body['customer_id'] = $customerId;
        }

        $res = $this->post('/virtual-account-numbers', $body);

        Log::info('[FLW V4] generateVirtualAccount', ['response' => $res]);

        if (!($res['ok'] ?? false)) {
            throw new RuntimeException('[FLW V4] Failed to generate virtual account: ' . ($res['raw']['message'] ?? 'unknown'));
        }

        $data = $res['raw']['data'] ?? [];

        return [
            'user_id'        => $payload['user']['id'] ?? null,
            'status'         => 'active',
            'provider'       => 'flutterwave',
            'account_number' => $data['account_number'] ?? '',
            'bank_name'      => $data['bank_name'] ?? '',
            'reference'      => $reference,
            'meta'           => $data,
        ];
    }

    // -------------------------------------------------------------------------
    // Webhook normalisation
    // -------------------------------------------------------------------------

    public function handleWebhook(array $payload): array
    {
        $data      = $payload['data'] ?? $payload;
        $rawStatus = strtolower((string) ($data['status'] ?? ''));

        $status = match (true) {
            in_array($rawStatus, ['succeeded', 'successful', 'success', 'paid', 'completed']) => Transaction::STATUS_SUCCESS,
            in_array($rawStatus, ['failed', 'error', 'cancelled'])                            => Transaction::STATUS_FAILED,
            default                                                                            => Transaction::STATUS_PENDING,
        };

        $reference = $data['reference']
            ?? $data['tx_ref']
            ?? $data['flw_ref']
            ?? $payload['reference']
            ?? null;

        return [
            'handled'               => true,
            'provider'              => 'flutterwave',
            'status'                => $status,
            'reference'             => $reference,
            'provider_reference'    => $data['id'] ?? ($data['flw_ref'] ?? null),
            'amount'                => $data['amount'] ?? null,
            'currency'              => $data['currency'] ?? null,
            'provider_webhook_type' => $payload['type'] ?? ($payload['event'] ?? 'charge.completed'),
            'customer'              => $data['customer'] ?? null,
            'raw'                   => $payload,
        ];
    }
}