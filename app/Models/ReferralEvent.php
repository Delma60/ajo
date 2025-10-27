<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralEvent extends Model
{
    protected $fillable = ['referral_id', 'transaction_id', 'type', 'meta'];

    protected $casts = [
        'meta' => 'array',
    ];

    public function referral(): BelongsTo { return $this->belongsTo(Referral::class); }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
}
