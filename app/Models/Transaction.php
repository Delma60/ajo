<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class Transaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'reference',
        'label',
        'idempotency_key',
        'user_id',
        'group_id',
        'pending_account_balance_id',
        'amount',
        'fee',
        'net_amount',
        'currency',
        'type',
        'direction',
        'provider',
        'method',
        'provider_reference',
        'status',
        'attempts',
        'meta',
        'scheduled_at',
        'processed_at',
        'expires_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'meta' => 'array',
        'scheduled_at' => 'datetime',
        'processed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // type constants
    public const TYPE_CHARGE  = 'charge';
    public const TYPE_PAYOUT  = 'payout';
    public const TYPE_REFUND  = 'refund';
    public const TYPE_TOPUP   = 'topup';
    public const TYPE_TRANSFER = 'transfer';

    public const DIRECTION_DEBIT  = 'debit';
    public const DIRECTION_CREDIT = 'credit';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_WALLET = 'wallet';
    public const METHOD_BANK = 'bank';
    public const METHOD_CARD = 'card';

    /**
     * Human-friendly provider display names
     * add keys you need here (lowercase keys expected)
     */
    protected static array $providerPretty = [
        'opay' => 'Opay',
        'flutterwave' => 'Flutterwave',
        'paystack' => 'Paystack',
        'monnify' => 'Monnify',
        'wallet' => 'Wallet',
        // add more providers as needed
    ];

    /**
     * Type -> human label mapping
     */
    protected static array $typeLabels = [
        self::TYPE_TOPUP => 'Top-up',
        self::TYPE_PAYOUT => 'Payout',
        self::TYPE_REFUND => 'Refund',
        self::TYPE_TRANSFER => 'Transfer',
        self::TYPE_CHARGE => 'Payment',
    ];

    protected $appends = [
        'short_label'
    ];

    protected static function booted()
    {
        static::creating(function (self $t) {
            if (empty($t->uuid)) {
                $t->uuid = (string) Str::uuid();
            }

            // ensure net_amount if not provided
            if (is_null($t->net_amount)) {
                $t->net_amount = $t->amount - ($t->fee ?? 0);
            }

            // generate label if not provided: delegate to generateLabel() and guard with try/catch
            if (empty($t->label)) {
                try {
                    $t->label = $t->generateLabel();
                } catch (\Throwable $ex) {
                    Log::warning('Transaction::generateLabel failed: ' . $ex->getMessage());
                    $t->label = self::$typeLabels[$t->type] ?? ucfirst($t->type ?? 'Transaction');
                }
            }
        });
    }

    // relations
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function group(): BelongsTo { return $this->belongsTo(Group::class); }
    public function pending(): BelongsTo { return $this->belongsTo(PendingAccountBalance::class, 'pending_account_balance_id'); }

    // helpers
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isSuccess(): bool { return $this->status === self::STATUS_SUCCESS; }
    public function markProcessing(): self
    {
        $this->status = self::STATUS_PROCESSING;
        $this->attempts = ($this->attempts ?? 0) + 1;
        $this->save();
        return $this;
    }

    public function markSuccess(string $providerReference = null, array $meta = []): self
    {
        $this->status = self::STATUS_SUCCESS;
        $this->provider_reference = $providerReference ?? $this->provider_reference;
        $this->processed_at = now();
        $this->meta = array_merge($this->meta ?? [], $meta);
        $this->save();
        return $this;
    }

    public function markFailed(array $meta = []): self
    {
        $this->status = self::STATUS_FAILED;
        $this->meta = array_merge($this->meta ?? [], $meta);
        $this->processed_at = now();
        $this->save();
        return $this;
    }

    /**
     * Idempotency guard - return existing model if idempotency_key exists
     */
    public static function firstOrCreateIdempotent(array $attributes, array $values = [])
    {
        if (!empty($attributes['idempotency_key'])) {
            $existing = self::where('idempotency_key', $attributes['idempotency_key'])->first();
            if ($existing) return $existing;
        }

        return self::create(array_merge($attributes, $values));
    }

    /* -------------------------
     * Label generation helpers
     * ------------------------- */

    protected function generateLabel(): string
{
    $type = $this->type ?? null;
    $meta = $this->meta ?? [];

    // helper: safe user name fetch (tries relation then lightweight find)
    $fetchUserName = function ($userId) {
        if (empty($userId)) return null;
        if ($this->relationLoaded('user') && $this->user) {
            return $this->user->name ?? null;
        }
        try {
            $u = \App\Models\User::find($userId);
            return $u ? ($u->name ?? null) : null;
        } catch (\Throwable $ex) {
            return null;
        }
    };

    // helper: safe group name fetch
    $fetchGroupName = function () {
        if ($this->relationLoaded('group') && $this->group) {
            return $this->group->name ?? null;
        }
        if (!empty($this->group_id)) {
            try {
                $g = \App\Models\Group::find($this->group_id);
                return $g ? $g->name : null;
            } catch (\Throwable $ex) {
                return null;
            }
        }
        return null;
    };

    // helper: look for first non-empty meta key from a list
    $metaValue = function (array $keys) use ($meta) {
        foreach ($keys as $k) {
            if (!empty($meta[$k])) return $meta[$k];
        }
        return null;
    };

    // amount string (if amount present)
    $amountStr = null;
    if (!is_null($this->amount)) {
        $currency = $this->currency ?? 'NGN';
        $amountStr = number_format((float)$this->amount, 2) . ' ' . strtoupper($currency);
    }

    // Determine common name holders from meta
    $senderName = $metaValue(['sender_name', 'from_user_name', 'from_name', 'payer_name', 'from']);
    $recipientName = $metaValue(['recipient_name', 'to_user_name', 'to_name', 'beneficiary_name', 'to']);
    $accountFrom = $metaValue(['from_account', 'from_account_number']);
    $accountTo = $metaValue(['to_account', 'to_account_number']);
    $merchantName = $metaValue(['merchant_name', 'business_name']);
    $investmentName = $metaValue(['investment_name', 'investment']);
    $sourceName = $metaValue(['source', 'source_name']);

    // Group hint
    $groupName = $fetchGroupName();

    // Convenience: decide if this is a reversal
    $isReversal = !empty($meta['reversed']) || !empty($meta['is_reversal']) || $type === self::TYPE_REFUND;

    $label = '';

    switch ($type) {
        case self::TYPE_TRANSFER:
            // Prefer clear "from" or "to" phrasing using direction and available meta.
            // If direction is credit -> user received money, so show "Transfer from <sender>"
            // If direction is debit -> user sent money, so show "Transfer to <recipient>"
            if ($this->direction === self::DIRECTION_CREDIT) {
                // credit => "Transfer from X"
                if ($senderName) {
                    $label = "Transfer from {$senderName}";
                } elseif ($accountFrom) {
                    $label = "Transfer from acct " . $this->maskAccountNumber($accountFrom);
                } else {
                    // fallback to any available recipientName but reverse wording
                    $label = $recipientName ? "Transfer from {$recipientName}" : "Transfer";
                }
            } else {
                // debit or unknown => "Transfer to X"
                if ($recipientName) {
                    $label = "Transfer to {$recipientName}";
                } elseif ($accountTo) {
                    $label = "Transfer to acct " . $this->maskAccountNumber($accountTo);
                } elseif ($senderName) {
                    // fallback
                    $label = "Transfer to {$senderName}";
                } else {
                    $label = "Transfer";
                }
            }
            break;

        case self::TYPE_PAYOUT:
            // User is receiving a payout from a group/investment OR it's a payout to bank (merchant pays out)
            if ($groupName) {
                $label = "Payout from {$groupName}";
            } elseif ($investmentName) {
                $label = "Payout from {$investmentName}";
            } elseif ($sourceName) {
                $label = "Payout from {$sourceName}";
            } elseif (!empty($meta['bank_name'])) {
                // if meta contains bank_name, this is likely a payout to that bank
                $label = "Payout to " . $meta['bank_name'];
            } else {
                $label = "Payout";
            }
            break;

        case self::TYPE_TOPUP:
            // Top-up — prefer note or source
            if ($sourceName) {
                $label = "Top-up from {$sourceName}";
            } elseif (!empty($meta['note'])) {
                $label = $meta['note'];
            } else {
                $label = "Top-up";
            }
            break;

        case self::TYPE_REFUND:
            // Refunds often mean reversal
            if ($isReversal) {
                $label = "Payment Reversed";
            } elseif (!empty($meta['note'])) {
                $label = "Refund — " . $meta['note'];
            } else {
                $label = "Refund";
            }
            break;

        case self::TYPE_CHARGE:
        default:
            // Payment / charge
            if ($isReversal) {
                $label = "Payment Reversed";
            } elseif (!empty($meta['note'])) {
                $label = $meta['note'];
            } elseif ($merchantName) {
                $label = "Payment to {$merchantName}";
            } else {
                $label = "Payment";
            }
            break;
    }

    // append user hint for clarity when appropriate (don't duplicate group)
    if (!empty($this->user_id) && empty($groupName)) {
        $userName = $fetchUserName($this->user_id);
        if ($userName) {
            
        }
    }

    // Finally append amount if present
    if ($amountStr) {
        return trim($label);
    }

    return trim($label);
}



    protected function prettyProviderName(string $key): ?string
    {
        $k = strtolower(trim($key));
        return self::$providerPretty[$k] ?? ($k ? ucfirst($k) : null);
    }

    protected function maskAccountNumber(string $acct): string
    {
        $acct = preg_replace('/\s+/', '', $acct);
        $len = strlen($acct);
        if ($len <= 4) return $acct;
        $visible = substr($acct, -4);
        return str_repeat('*', max(0, $len - 4)) . $visible;
    }

    public function getShortLabelAttribute(): string
    {
        // prefer stored label; fall back to generated
        $full = $this->label ?? $this->generateLabel();

        // configurable length (put in config/transactions.php or .env if you like)
        $max = config('transactions.short_label_length', 60); // default 60 chars

        // if already short enough, return as-is
        if (mb_strlen($full) <= $max) {
            return $full;
        }

        // If label contains an amount separated by ' • ', keep the amount and truncate the rest
        if (str_contains($full, ' • ')) {
            [$main, $amountPart] = explode(' • ', $full, 2);
            $amountPart = trim($amountPart);

            // space for an ellipsis and the separator
            $available = max(10, $max - mb_strlen(' • ' . $amountPart) - 1); // ensure some minimum
            $shortMain = mb_substr(trim($main), 0, $available);

            // if we truncated mid-word, tidy a bit (optional)
            $shortMain = rtrim($shortMain);

            return $shortMain . '…' . ' • ' . $amountPart;
        }

        // Generic truncation: preserve start, add ellipsis
        $short = mb_substr($full, 0, $max - 1);
        return rtrim($short) . '…';
    }
}
