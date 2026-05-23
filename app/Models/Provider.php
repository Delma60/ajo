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

    protected $appends = ['fees', 'supported_methods', 'version', 'total_transactions'];

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

    // version atribute for flutterwave provider to determine which API version to use
    public function getVersionAttribute()
    {
        return $this->meta['version'] ?? 'v3';
    }
}

