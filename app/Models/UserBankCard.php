<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBankCard extends Model
{
    // in App\Models\User.php
    protected $fillable = [
        "user_id",
        "customer_id",
        "card_id"
    ];

     public function user()
    {
        return $this->belongsTo(User::class);
    }

    // pivot -> bank card
    public function bankCard()
    {
        return $this->belongsTo(BankCard::class, 'card_id');
    }

}
