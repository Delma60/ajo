<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupCycle extends Model
{
    //
    protected $fillable = [
        'amount', 
        "start_up", 
        "end_at", 
        "cycle_number", 
        "group_id", 
        "recipient"
    ];

    protected $with = ['recipientUser'] ;


    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient');
    }
}
