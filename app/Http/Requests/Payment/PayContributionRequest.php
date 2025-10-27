<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class PayContributionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_id' => 'required|exists:users,id',
            'group_id' => 'required|exists:groups,id',
            'card_id' => 'sometimes|exists:bank_cards,id',
            'amount' => 'required|numeric|min:0.01',
            'provider' => 'sometimes|string',
            'reference' => 'sometimes|string',
            'use_wallet' => 'sometimes|boolean',
        ];
    }
}
