<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VirtualBank extends Model
{
    //
    protected $fillable = [
        'user_id',
        'provider',
        'account_number',
        'bank_name',
        'status',
        'reference',
        'meta',
    ];

    protected $casts = [
        'meta' => 'json',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
