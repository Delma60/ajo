<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    //
    protected $fillable = [
        'name', 'slug', 'is_active', 'is_default', 'mode'
        , 'public_key', 'secret_key', 'webhook_secret', 'meta',

    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'meta' => 'array',
    ];

    protected $appends = ['fees', 'supported_methods', 'total_transactions'];

    public function getFeesAttribute()
    {
        return $this->meta['fees'] ?? null;
    }

    public function getSupportedMethodsAttribute()
    {
        return $this->meta['supported_methods'] ?? null;
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'provider_id');
    }

    public function getTotalTransactionsAttribute()
    {
        return $this->transactions()->count();
    }
}

