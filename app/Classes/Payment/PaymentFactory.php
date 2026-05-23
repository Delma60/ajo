<?php

namespace App\Classes\Payment;

use App\Classes\Payment\Flutterwave\FlutterwaveProvider;
use App\Models\Provider;
use RuntimeException;

class PaymentFactory
{
    /**
     * Resolve a PaymentInterface implementation by provider slug.
     *
     * Loads the Provider row from the database so API keys / version are
     * always read from the single source of truth, not from .env alone.
     *
     * @param  string  $slug  e.g. "flutterwave", "paystack"
     * @return PaymentInterface
     * @throws RuntimeException when the slug is unknown or the provider is inactive
     */
    public static function provider(string $slug = 'flutterwave'): PaymentInterface
    {
        $slug = strtolower(trim($slug));

        // Try to load from DB; fall back gracefully when running outside app context (e.g. unit tests)
        $providerModel = Provider::where('slug', $slug)->first();

        return match ($slug) {
            'flutterwave' => new FlutterwaveProvider($providerModel),
            'paystack'    => new PaystackProvider(),
            'system'      => new SystemProvider(),
            default       => throw new RuntimeException("Unknown payment provider: [{$slug}]"),
        };
    }
}