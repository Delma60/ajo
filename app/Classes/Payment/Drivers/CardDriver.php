<?php
namespace App\Classes\Payment\Drivers;

use App\Classes\Payment\FlutterwaveProvider;
use App\Models\BankCard;
use App\Models\UserBankCard;
use Illuminate\Support\Facades\Log;

class CardDriver
{
    protected FlutterwaveProvider $provider;

    public function __construct(FlutterwaveProvider $provider)
    {
        $this->provider = $provider;
    }

    public function __invoke(array $payload):mixed
    {
        $card = BankCard::findOrFail($payload['card_id']);
        $cardPivot = UserBankCard::where("user_id", $payload['user_id'])->firstOrFail();
        $cardArray = array_merge($card->toResource()->toArray(Request()), [
            "nonce" => $this->nonce()
        ]);
        return $this->provider->cardDriver(array_merge($payload, ["card" => $cardArray, "customer_id" => $cardPivot->customer_id]));
    }

    public function nonce($length=12){
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $nonce = '';
        for ($i = 0; $i < $length; $i++) {
            $nonce .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $nonce;
    }
}
