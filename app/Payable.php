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
        $payment = PaymentFactory::provider($provider)
            ->charge("card", array_merge($meta, [
                'amount' => $amount,
                'currency' => $meta['currency'] ?? 'NGN',
                'idempotency_key' => $meta['idempotency_key'] ?? null,
            ]));

        // build idempotency key (prefer provided, else deterministic)
        $idemp = $meta['idempotency_key'] ?? ($payment['idempotency_key'] ?? "provider:{$provider}:".md5($provider . '|' . $amount . '|' . ($this->id ?? 'guest')));

        // create transaction row idempotently (status pending)
        $tx = Transaction::firstOrCreateIdempotent(
            ['idempotency_key' => $idemp],
            [
                'uuid' => (string) Str::uuid(),
                'reference' => $payment['reference'] ?? ($meta['reference'] ?? null),
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
        $amount = (float) $amount;
        if ($amount <= 0) {
            throw new Exception('Invalid amount');
        }

        return DB::transaction(function () use ($amount, $meta) {
            Log::info("Withdrawing {$amount} from user {$this->id} wallet");
            $available = (float) ($this->available_wallet ?? 0);

            if ($available < $amount) {
                throw new Exception('Insufficient wallet balance');
            }

            // decrement available_wallet atomically
            // use decrement to avoid race conditions if multiple requests happen concurrently
            $fee = $meta['fee'] ?? 0;
            $net = $amount - $fee;
            if ($meta['type'] === 'wallet') {
                $payload = [
                    'uuid' => (string) Str::uuid(),
                    'reference' => $meta['reference'] ?? "wallet_withdraw:{$this->id}:" . time(),
                        'idempotency_key' => $meta['idempotency_key'] ?? "wallet_withdraw_user_{$this->id}_" . md5($amount . ':' . ($meta['reference'] ?? '')),
                    'user_id' => $this->id,
                    'amount' => $amount,
                    'fee' => $fee,
                    'net_amount' => $net,
                    'currency' => $meta['currency'] ?? 'NGN',
                    'type' => Transaction::TYPE_CHARGE,
                    'direction' => Transaction::DIRECTION_DEBIT,
                    'provider' => 'wallet',
                    'method' => Transaction::METHOD_WALLET,
                    'provider_reference' => null,
                    'status' => Transaction::STATUS_SUCCESS,
                    'attempts' => 0,
                    'meta' => $meta,
                    'processed_at' => now(),
                ];
                
            } else {
                
                $transfer = PaymentFactory::provider()->transfer([
                    "bank" => Bank::find($meta['bank_id'])->toArray(),
                    "amount_to_be_paid" => $amount - $fee,
                    "user" => $this->toArray(),
                    "fee" => $meta['fee'],
                    "reference" => $meta['reference'],
                ]);
                $payload = [
                    'uuid' => (string) Str::uuid(),
                    'reference' => $meta['reference'],
                    'idempotency_key' => $meta['idempotency_key'] ?? "wallet_withdraw_user_{$this->id}_" . md5($amount . ':' . ($meta['reference'] ?? '')),
                    'user_id' => $this->id,
                    'amount' => $amount,
                    'fee' => $fee,
                    'net_amount' => $net,
                    'currency' => $meta['currency'] ?? 'NGN',
                    'type' => Transaction::TYPE_TRANSFER,
                    'direction' => Transaction::DIRECTION_DEBIT,
                    'provider' => $transfer['provider'],
                    'method' => Transaction::METHOD_BANK,
                    'provider_reference' => $transfer['provider_reference'],
                    'status' => Transaction::STATUS_PENDING,
                    'attempts' => 0,
                    'meta' => $transfer['meta'],
                    'processed_at' => now(),
                ];
            }

           $this->decrement('available_wallet', $amount);
           $tx = $this->transactions()->create($payload);

           // notify user about the withdrawal (success)
           try {
               $this->notify(new TransactionNotification($tx, 'success'));
           } catch (\Throwable $ex) {
               Log::warning('Failed to send withdrawal notification: ' . $ex->getMessage());
           }

            return $tx;
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
        $amount = (float) $amount;
        if ($amount <= 0) {
            throw new Exception('Invalid amount');
        }

        return DB::transaction(function () use ($amount, $meta) {
            // increment wallet balance safely
            $this->increment('available_wallet', $amount);

            // choose type: if meta specifies 'payout' assume a payout; else 'topup'
            $type = $meta['type'] ?? Transaction::TYPE_TOPUP;
            $direction = Transaction::DIRECTION_CREDIT;
            $fee = $meta['fee'] ?? 0;
            $net = $amount - $fee;

            $tx = $this->transactions()->create([
                'uuid' => (string) Str::uuid(),
                'reference' => $meta['reference'] ?? "{$type}:user:{$this->id}:" . time(),
                'label' => $meta['label'] ?? null,
                'idempotency_key' => $meta['idempotency_key'] ?? "{$type}_user_{$this->id}_" . md5($amount . ':' . ($meta['reference'] ?? '')),
                'user_id' => $this->id,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $net,
                'currency' => $meta['currency'] ?? 'NGN',
                'type' => $type,
                'direction' => $direction,
                'provider' => $meta['provider'] ?? 'system',
                'method' => $meta['method'] ?? null,
                'provider_reference' => $meta['provider_reference'] ?? null,
                'status' => Transaction::STATUS_SUCCESS,
                'attempts' => 0,
                'meta' => $meta,
                'processed_at' => now(),
            ]);

            // notify user about topup/credit
            try {
                $this->notify(new TransactionNotification($tx, 'success'));
            } catch (\Throwable $ex) {
                Log::warning('Failed to send credit notification: ' . $ex->getMessage());
            }
            return $tx;
        });
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
