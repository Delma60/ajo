<?php

namespace App\Http\Controllers;

use App\Classes\Payment\PaymentFactory;
use App\Models\BankCard;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserBankCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BankCardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        //
        $data = $request->validate([
            "user_id" => "required|exists:users,id"
        ]);
        $cards = BankCard::where("user_id", $data['user_id'])->get();
        return $cards->toResourceCollection();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        try {
            # code...
            $data = $request->validate([
                'user_id' => 'required|exists:users,id',
                "brand" => "required|string",
                'card_number' => ['nullable', 'string'],
                'exp_month' => ['nullable', 'string'],
                'exp_year' => ['nullable', 'string'],
                'cvv' => ['nullable', 'string'],
                'nonce' => ['nullable', 'string'],
                'is_default' => ['nullable', 'boolean'],
            ]);
            $payment = PaymentFactory::provider("flutterwave");
            $user = User::find($data['user_id']);
            $pi = null;
            if(!$user->hasCustomerId()){
                $res = $payment->createCustomer($user->toArray());
                $pi = UserBankCard::create([
                    "user_id" => $data['user_id'],
                    "customer_id" => $res['customer_id']
                ]);
            }
            $bankCard = BankCard::create([
                "user_id" => $user->id,
                "exp_month" => $data['exp_month'],
                "exp_year" => $data['exp_year'],
                "brand" => $data['brand'],
                "meta" => [
                    "card_number" => $data['card_number'],
                    "cvv" => $data['cvv'],
                    // "non" => $data['card_number'],
                ]
            ]);

            if($pi){
                $pi->update([
                    "card_id" > $bankCard->id
                ]);

            }
            Log::info("Card Controller");
            return $bankCard->toResource();
        } catch (\Throwable $e) {
            # code...
            throw $e;
        }

        // $user->cards()->create([]);

    }

    /**
     * Display the specified resource.
     */
    public function show(BankCard $bankCard)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BankCard $bankCard)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BankCard $bankCard)
    {
        //
    }
}
