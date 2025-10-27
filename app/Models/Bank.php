<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    //
    protected $fillable = [
        'user_id',
        "account_name",
        "account_number",
        "bank_name",
        'meta'
    ];

    protected $casts = [
        "meta" => "json"
    ];

    function users(){
        return $this->belongsTo(User::class);
    }

}
