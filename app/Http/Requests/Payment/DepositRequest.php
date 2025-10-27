<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class DepositRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            "method" => "required|string",
            "user_id" => "required|exists:users,id",
            "card_id" => "sometimes|exists:bank_cards,id",
            'amount' => 'required|numeric|min:0.01',
            'provider' => 'sometimes|string',
            'reference' => 'sometimes|string',
            'from_pending' => 'sometimes|boolean',
        ];
    }
}
