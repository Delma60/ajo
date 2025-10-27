<?php

namespace App\Classes\Payment;

class PaymentFactory
{
    /**
     * Return an instance of a provider by key.
     *
     * @param string $provider
     * @return PaymentInterface
     */
    public static function provider(string $provider="flutterwave"): PaymentInterface
    {
        $key = strtolower($provider);
        return match ($key) {
             "flutterwave" => new FlutterwaveProvider()
        };
    }
}
