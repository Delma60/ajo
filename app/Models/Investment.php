<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Investment extends Model
{
    //
    protected $fillable = [
        "title",
        "subtitle",
        "description",
        "min_investment",
        "status",
        "raised",
        "target",
        "start_date",
        "end_date",
        "apy",
        "duration",
        "meta",
    ];

    protected $casts = [
        "meta" => "json"
    ];

    function investors(){
        return $this->belongsToMany(User::class)->withPivot('amount')->withTimestamps();
    }
}
