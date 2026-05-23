<?php

namespace App\Classes\Payment\Drivers;

use App\Classes\Payment\PaymentInterface;
use App\Models\BankCard;
use App\Models\UserBankCard;

/**
 * CardDriver
 *
 * Bridges a generic charge() call into the provider's card-specific flow.
 * Invoked via: PaymentFactory::provider('flutterwave')->driver('card')($payload)
 */
class CardDriver
{
    protected PaymentInterface $provider;

    public function __construct(PaymentInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * @param array $payload  Must include: card_id, user_id, amount, reference, currency?
     */
    public function __invoke(array $payload): mixed
    {
        $card     = BankCard::findOrFail($payload['card_id']);
        $cardPivot = UserBankCard::where('user_id', $payload['user_id'])->firstOrFail();

        // Build the card sub-array expected by the provider (includes raw numbers + nonce)
        $cardArray = array_merge(
            $card->toResource()->toArray(request()),
            ['nonce' => $this->nonce()]
        );

        return $this->provider?->cardDriver(
            array_merge($payload, [
                'card'        => $cardArray,
                'customer_id' => $cardPivot->customer_id,
            ])
        );
    }

    protected function nonce(int $length = 12): string
    {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $result;
    }
}