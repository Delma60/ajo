<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class WithdrawRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'fee' => 'sometimes|numeric|min:0.01',
            'reference' => 'sometimes|string',
            'user_id' => 'required|exists:users,id',
            'bank_id' => 'sometimes|exists:banks,id',
            'note' => 'nullable|string',
            'type' => 'required|in:bank,wallet',
        ];
    }
}
