<?php

namespace App\Services;

use App\Models\Transaction;

class NotificationService
{
    /**
     * Build a standardized notification payload for transactions.
     * Returns an array with keys: title, body, type, reference, transaction_id, amount, meta, link, silent
     *
     * @param Transaction|array|null $tx
     * @param string $status 'success'|'pending'|'failed'
     * @param array $extra
     * @return array
     */
    public static function buildTransactionPayload($tx, string $status = 'success', array $extra = []): array
    {
        $payload = [
            'type' => $status,
            'title' => self::titleForTransaction($tx, $status, $extra),
            'body' => self::bodyForTransaction($tx, $status, $extra),
            'transaction_id' => is_object($tx) && isset($tx->id) ? $tx->id : ($tx['id'] ?? null),
            'reference' => is_object($tx) && isset($tx->reference) ? $tx->reference : ($tx['reference'] ?? ($tx['uuid'] ?? null)),
            'amount' => is_object($tx) && isset($tx->amount) ? (float) $tx->amount : ($tx['amount'] ?? ($extra['amount'] ?? null)),
            'meta' => array_merge(is_object($tx) && isset($tx->meta) ? ($tx->meta ?? []) : ($tx['meta'] ?? []), $extra),
            'link' => self::linkForTransaction($tx, $extra),
            'silent' => $extra['silent'] ?? false,
        ];

        return $payload;
    }

    protected static function titleForTransaction($tx, string $status, array $extra = []): string
    {
        // Prefer explicit titles in extra
        if (!empty($extra['title'])) return $extra['title'];

        $type = is_object($tx) ? ($tx->type ?? null) : ($tx['type'] ?? null);

        if ($type === Transaction::TYPE_TOPUP) return $status === 'success' ? 'Top-up successful' : ($status === 'pending' ? 'Top-up pending' : 'Top-up failed');
        if ($type === Transaction::TYPE_PAYOUT) return $status === 'success' ? 'Payout processed' : ($status === 'pending' ? 'Payout pending' : 'Payout failed');
        if ($type === Transaction::TYPE_TRANSFER) return $status === 'success' ? 'Transfer completed' : ($status === 'pending' ? 'Transfer pending' : 'Transfer failed');
        if ($type === Transaction::TYPE_REFUND) return 'Payment reversed';
        if ($type === Transaction::TYPE_CHARGE) return 'Payment ' . ($status === 'success' ? 'successful' : ($status === 'pending' ? 'pending' : 'failed'));

        return 'Transaction update';
    }

    protected static function bodyForTransaction($tx, string $status, array $extra = []): string
    {
        if (!empty($extra['body'])) return $extra['body'];

        if (is_object($tx)) {
            $label = $tx->label ?? ($tx->generateLabel() ?? null);
            $amount = isset($tx->amount) ? number_format((float)$tx->amount, 2) : null;
        } else {
            $label = $tx['label'] ?? ($tx['meta']['note'] ?? null);
            $amount = isset($tx['amount']) ? number_format((float)$tx['amount'], 2) : null;
        }

        $parts = [];
        if ($label) $parts[] = $label;
        if ($amount) $parts[] = $amount . ' NGN';

        return implode(' — ', $parts) ?: ($status === 'success' ? 'Transaction completed' : 'Transaction status updated');
    }

    protected static function linkForTransaction($tx, array $extra = null): ?string
    {
        // If transaction relates to a group, link to group page
        if (is_object($tx) && !empty($tx->group_id)) return '/groups/' . $tx->group_id;
        if (is_array($tx) && !empty($tx['group_id'])) return '/groups/' . $tx['group_id'];

        return $extra['link'] ?? null;
    }
}
