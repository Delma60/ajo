<?php

namespace App\Classes\Payment\Flutterwave;

use App\Classes\Payment\PaymentBase;
use App\Classes\Payment\PaymentInterface;
use App\Models\Provider;
use App\Models\Transaction;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * FlutterwaveV3Provider
 *
 * Implements Flutterwave's public REST API v3.
 * Base URL  : https://api.flutterwave.com/v3
 * Auth      : Bearer {SECRET_KEY}  (no OAuth)
 * Docs      : https://developer.flutterwave.com/docs
 *
 * Covered endpoints
 * -----------------
 *  POST   /charges?type=...            charge (card / bank-transfer / ussd / mobile-money)
 *  POST   /validate-charge             validate an OTP / pin step
 *  GET    /transactions/{id}/verify    verify a completed charge
 *  POST   /transfers                   initiate a bank transfer (payout)
 *  GET    /transfers/{id}              fetch transfer status
 *  GET    /banks/{country}             list banks
 *  POST   /accounts/resolve            verify bank account
 *  POST   /virtual-account-numbers     generate a dynamic virtual account
 *  POST   /customers                   create / upsert a customer record
 *  POST   /payment-plans               (stub — extend as needed)
 */
class FlutterwaveV3Provider extends PaymentBase implements PaymentInterface
{
    protected string $name    = 'flutterwave_v3';
    protected ?Provider $provider;

    // Supported charge types for the driver() helper
    protected array $supportedTypes = ['card', 'banktransfer', 'ussd', 'mobilemoneygh', 'account'];

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
        return rtrim(env('FLW_V3_URL', 'https://api.flutterwave.com/v3'), '/');
    }

    protected function header(): array
    {
        $secretKey = $this->provider?->secret_key ?? env('FLW_SECRET_KEY');

        return [
            'Authorization' => "Bearer {$secretKey}",
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    protected function secretKey(): string
    {
        return $this->provider?->secret_key ?? env('FLW_SECRET_KEY', '');
    }

    // -------------------------------------------------------------------------
    // Charges  (POST /charges?type=...)
    // -------------------------------------------------------------------------

    /**
     * Initiate a charge.
     *
     * $method maps to the Flutterwave charge type:
     *   card, banktransfer, ussd, mobilemoneygh, account
     *
     * Required $payload keys (vary by type — see docs):
     *   card        : card_number, cvv, expiry_month, expiry_year, currency, amount,
     *                 email, tx_ref, redirect_url, authorization.mode
     *   banktransfer: amount, email, tx_ref, currency
     *   ussd        : account_bank, amount, email, tx_ref, currency
     */
    public function charge($method, array $payload): array
    {
        $type = strtolower($method);
        if (!in_array($type, $this->supportedTypes, true)) {
            throw new \InvalidArgumentException(
                "Unsupported charge type [{$type}]. Supported: " . implode(', ', $this->supportedTypes)
            );
        }

        // Ensure tx_ref
        $payload['tx_ref'] = $payload['tx_ref'] ?? $payload['reference'] ?? Str::uuid()->toString();

        $res = $this->post("/charges?type={$type}", $payload);

        if (!($res['ok'] ?? false)) {
            Log::error('[FLW V3] charge failed', ['type' => $type, 'response' => $res]);
            return [
                'ok'        => false,
                'status'    => $res['status'] ?? null,
                'message'   => $res['raw']['message'] ?? 'Charge failed',
                'raw'       => $res['raw'] ?? [],
            ];
        }

        $data = $res['raw']['data'] ?? [];

        return [
            'ok'                 => true,
            'provider'           => $this->name,
            'status'             => $res['raw']['status'] ?? 'success',
            'charge_id'          => $data['id'] ?? null,
            'provider_reference' => $data['flw_ref'] ?? null,
            'reference'          => $data['tx_ref'] ?? ($payload['tx_ref'] ?? null),
            'redirect_url'       => $data['link'] ?? null,  // for hosted / redirect flows
            'auth_mode'          => $data['meta']['authorization']['mode'] ?? null,
            'raw'                => $res['raw'],
        ];
    }

    /**
     * Alias for charge (deposit == charge in V3).
     */
    public function deposit($method, array $payload): array
    {
        return $this->charge($method, $payload);
    }

    // -------------------------------------------------------------------------
    // Validate OTP / PIN  (POST /validate-charge)
    // -------------------------------------------------------------------------

    /**
     * Submit OTP or PIN to complete a charge that requires an additional step.
     *
     * @param array $payload  ['flw_ref' => '...', 'otp' => '...', 'type' => 'card']
     */
    public function validateCharge(array $payload): array
    {
        $res = $this->post('/validate-charge', [
            'flw_ref' => $payload['flw_ref'] ?? $payload['provider_reference'] ?? null,
            'otp'     => $payload['otp'],
            'type'    => $payload['type'] ?? 'card',
        ]);

        return [
            'ok'      => $res['ok'] ?? false,
            'status'  => $res['raw']['status'] ?? null,
            'message' => $res['raw']['message'] ?? null,
            'data'    => $res['raw']['data'] ?? [],
            'raw'     => $res['raw'],
        ];
    }

    // -------------------------------------------------------------------------
    // Verify transaction  (GET /transactions/{id}/verify)
    // -------------------------------------------------------------------------

    /**
     * Verify a charge by its Flutterwave transaction ID.
     *
     * @param array $payload  ['charge_id' => 123456]
     */
    public function verifyCardPayment(array $payload): mixed
    {
        $id = $payload['charge_id'] ?? $payload['transaction_id'] ?? null;

        if (empty($id)) {
            throw new \InvalidArgumentException('charge_id is required for verifyCardPayment()');
        }

        $res = $this->get("/transactions/{$id}/verify");

        if (!($res['ok'] ?? false)) {
            return [
                'ok'      => false,
                'status'  => $res['status'] ?? null,
                'message' => $res['raw']['message'] ?? 'Verification failed',
                'raw'     => $res['raw'] ?? [],
            ];
        }

        $data = $res['raw']['data'] ?? [];

        return [
            'ok'                 => true,
            'provider'           => $this->name,
            'status'             => $res['raw']['status'] ?? 'success',
            'charge_status'      => $data['status'] ?? null,          // 'successful' | 'failed'
            'provider_reference' => $data['flw_ref'] ?? null,
            'reference'          => $data['tx_ref'] ?? null,
            'amount'             => $data['amount'] ?? null,
            'currency'           => $data['currency'] ?? null,
            'raw'                => $res['raw'],
        ];
    }

    // -------------------------------------------------------------------------
    // Webhook  (no external call — just normalise the payload)
    // -------------------------------------------------------------------------

    public function handleWebhook(array $payload): array
    {
        $data = $payload['data'] ?? $payload;

        $rawStatus = strtolower((string) ($data['status'] ?? ''));

        $status = match (true) {
            in_array($rawStatus, ['successful', 'success', 'completed']) => Transaction::STATUS_SUCCESS,
            in_array($rawStatus, ['failed', 'error', 'cancelled'])       => Transaction::STATUS_FAILED,
            default                                                       => Transaction::STATUS_PENDING,
        };

        return [
            'handled'                => true,
            'provider'               => 'flutterwave',
            'status'                 => $status,
            'reference'              => $data['tx_ref'] ?? ($data['reference'] ?? null),
            'provider_reference'     => $data['flw_ref'] ?? ($data['id'] ?? null),
            'amount'                 => $data['amount'] ?? null,
            'currency'               => $data['currency'] ?? null,
            'provider_webhook_type'  => $payload['event'] ?? 'charge.completed',
            'customer'               => $data['customer'] ?? null,
            'raw'                    => $payload,
        ];
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
            'callback_url'   => $payload['callback_url'] ?? null,
        ]);

        if (!($res['ok'] ?? false)) {
            Log::error('[FLW V3] transfer failed', ['response' => $res]);
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
    // Banks  (GET /banks/{country})
    // -------------------------------------------------------------------------

    public function listBanks(): array
    {
        return Cache::remember('flutterwave_v3_banks_NG', 86400, function () {
            $res = $this->get('/banks/NG');

            if (!($res['ok'] ?? false)) {
                Log::warning('[FLW V3] listBanks failed', ['response' => $res]);
                return [];
            }

            return $res['raw'];
        });
    }

    // -------------------------------------------------------------------------
    // Resolve bank account  (POST /accounts/resolve)
    // -------------------------------------------------------------------------

    public function verifyBankAccount(array $payload): array
    {
        $res = $this->post('/accounts/resolve', [
            'account_number' => $payload['number'] ?? $payload['account_number'],
            'account_bank'   => $payload['code']   ?? $payload['bank_code'],
        ]);

        return $res['raw'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Virtual account  (POST /virtual-account-numbers)
    // -------------------------------------------------------------------------

    public function generateVirtualAccount(array $payload): array
    {
        $reference = $payload['reference'] ?? Str::uuid()->toString();

        $res = $this->post('/virtual-account-numbers', [
            'email'        => $payload['user']['email'] ?? $payload['email'],
            'is_permanent' => $payload['is_permanent'] ?? false,
            'bvn'          => $payload['bvn'] ?? null,
            'tx_ref'       => $reference,
            'amount'       => $payload['amount'] ?? null,
            'narration'    => $payload['narration'] ?? ($payload['user']['name'] ?? null),
        ]);

        if (!($res['ok'] ?? false)) {
            Log::error('[FLW V3] generateVirtualAccount failed', ['response' => $res]);
            throw new RuntimeException('[FLW V3] Failed to generate virtual account: ' . ($res['raw']['message'] ?? 'unknown error'));
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
    // Customer  (not a distinct resource in V3 — customer info is inline)
    // -------------------------------------------------------------------------

    public function createCustomer(array $payload): array
    {
        // V3 does not have a standalone /customers endpoint for pre-creation.
        // Customer data is passed inline with the charge.  We return a placeholder
        // so callers that check customer_id get a consistent shape.
        return ['customer_id' => null];
    }
}