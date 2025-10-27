<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankCard extends Model
{
    //
    protected $fillable = [
        'user_id',
        'provider',
        'provider_payment_method_id',
        'brand',
        'bank_name',
        'country',
        'currency',
        'last4',
        'exp_month',
        'exp_year',
        'is_default',
        'status',
        'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'meta' => 'json',
    ];

    public function users(){
        return $this->hasMany(BankCard::class);
    }
}
