<?php

namespace App;

use App\Classes\Payment\PaymentFactory;
use App\Models\Bank;
use App\Models\Transaction;
use App\Notifications\TransactionNotification;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

trait Payable
{
    /**
     * Relationship: user's transactions
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'user_id');
    }

    /**
     * Charge the user using a provider or wallet.
     *
     * - If provider === 'wallet' it withdraws from available_wallet immediately.
     * - For external providers, it creates an idempotent pending Transaction and returns it.
     *
     * @param float $amount
     * @param string $provider one of 'wallet', 'system', 'flutterwave', 'paystack', etc.
     * @param array $meta optional metadata (pass 'idempotency_key' if you have one)
     * @return Transaction
     * @throws \Exception when insufficient balance for wallet
     */
    public function charge(float $amount, string $provider = 'system', array $meta = []): Transaction
    {
        // normalize amount
        $amount = (float) $amount;
        if ($amount <= 0) {
            throw new Exception('Invalid amount');
        }

        // If paying directly from wallet, use withdrawFromWallet
        if ($provider === 'wallet') {
            return $this->withdrawFromWallet($amount, $meta);
        }

        // call provider to create payment intent / charge (depends on implementation)
        $method = $meta['method'] ?? 'card';
        $payment = PaymentFactory::provider($provider)
            ->charge($method, array_merge($meta, [
                'amount' => $amount,
                'currency' => $meta['currency'] ?? 'NGN',
                'idempotency_key' => $meta['idempotency_key'] ?? null,
            ]));

        $reference = $meta['reference'] ?? uniqid('', true);
        $idemp = $meta['idempotency_key'] ?? md5($provider . '|' . $amount . '|' . $reference . '|' . ($this->id ?? 'guest'));

        // create transaction row idempotently (status pending)
        $tx = Transaction::firstOrCreateIdempotent(
            ['idempotency_key' => $idemp],
            [
                'uuid' => (string) Str::uuid(),
                'reference' => $reference,
                'label' => $meta['label'] ?? null,
                'user_id' => $this->id ?? null,
                'amount' => $amount,
                'fee' => $payment['fee'] ?? ($meta['fee'] ?? 0),
                'net_amount' => $payment['net_amount'] ?? ($amount - ($payment['fee'] ?? ($meta['fee'] ?? 0))),
                'currency' => $payment['currency'] ?? ($meta['currency'] ?? 'NGN'),
                'type' => Transaction::TYPE_CHARGE,
                'direction' => Transaction::DIRECTION_DEBIT,
                'provider' => $provider,
                'method' => $payment['method'] ?? ($meta['method'] ?? null),
                'provider_reference' => $payment['provider_reference'] ?? null,
                'status' => Transaction::STATUS_PENDING,
                'attempts' => 0,
                'meta' => array_merge($meta, ['provider_payload' => $payment]),
                'scheduled_at' => now(),
            ]
        );

        return $tx;
    }

    /**
     * Withdraw from user's available_wallet and record transaction immediately.
     * Throws exception if insufficient funds.
     *
     * This operation is atomic (DB transaction).
     *
     * @param float $amount
     * @param array $meta
     * @return Transaction
     * @throws \Exception
     */
    public function withdrawFromWallet(float $amount, array $meta = []): Transaction
    {
        // Use DB transaction and lockForUpdate to prevent race conditions
        return DB::transaction(function () use ($amount, $meta) {
            $user = static::where('id', $this->id)->lockForUpdate()->first();
            if ($user->available_wallet < $amount) {
                throw new \Exception('Insufficient wallet balance');
            }
            $user->decrement('available_wallet', $amount);

            $type = $meta['type'] ?? Transaction::TYPE_TRANSFER;
            $fee = $meta['fee'] ?? 0;
            $net = $amount - $fee;
            $idempotencyKey = $meta['idempotency_key'] ?? Str::uuid();
            //$meta['idempotency_key'] ?? "wallet_withdraw_user_{$user->id}_" . md5($amount . ':' . ($meta['reference'] ?? ''));

            return $user->transactions()->firstOrCreateIdempotent(
                ['idempotency_key' => $idempotencyKey],
                [
                    'uuid' => (string) Str::uuid(),
                    'reference' => $meta['reference'] ?? "wallet_withdraw:{$user->id}:" . time(),
                    'label' => $meta['label'] ?? null,
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'fee' => $fee,
                    'net_amount' => $net,
                    'currency' => $meta['currency'] ?? 'NGN',
                    'type' => $type,
                    'direction' => Transaction::DIRECTION_DEBIT,
                    'provider' => 'wallet',
                    'method' => Transaction::METHOD_WALLET,
                    'provider_reference' => null,
                    'status' => Transaction::STATUS_SUCCESS,
                    'attempts' => 0,
                    'meta' => $meta,
                    'processed_at' => now(),
                ]
            );
        });
    }

    /**
     * Add funds to user's available_wallet (e.g. after successful webhook)
     *
     * - $meta may contain 'type' => 'payout'|'topup' etc. We pick type accordingly.
     *
     * @param float $amount
     * @param array $meta
     * @return Transaction
     */
    public function creditToWallet(float $amount, array $meta = []): Transaction
    {
        $this->increment('available_wallet', $amount);

        $type = $meta['type'] ?? Transaction::TYPE_TOPUP;
        $direction = Transaction::DIRECTION_CREDIT;
        $fee = $meta['fee'] ?? 0;
        $net = $amount - $fee;
        $idempotencyKey = $meta['idempotency_key'] ?? "wallet_credit_user_{$this->id}_" . md5($amount . ':' . ($meta['reference'] ?? ''));

        return $this->transactions()->firstOrCreateIdempotent(
            ['idempotency_key' => $idempotencyKey],
            [
                'uuid' => (string) Str::uuid(),
                'reference' => $meta['reference'] ?? "wallet_credit:{$this->id}:" . time(),
                'label' => $meta['label'] ?? null,
                'user_id' => $this->id,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $net,
                'currency' => $meta['currency'] ?? 'NGN',
                'type' => $type,
                'direction' => $direction,
                'provider' => 'wallet',
                'method' => Transaction::METHOD_WALLET,
                'provider_reference' => null,
                'status' => Transaction::STATUS_SUCCESS,
                'attempts' => 0,
                'meta' => $meta,
                'processed_at' => now(),
            ]
        );
    }

    /**
     * Helper: get contributions (sum) across groups or as collection
     * By default returns total contributed across pivots or transactions related to groups
     */
    public function getContributionsAttribute()
    {
        // sum pivot contributed value
        if (method_exists($this, 'groups')) {
            return $this->groups->sum(function ($g) {
                return $g->pivot->contributed ?? 0;
            });
        }

        // fallback: sum transactions of type CHARGE (debits)
        return $this->transactions->where('type', Transaction::TYPE_CHARGE)->sum('amount');
    }
}
