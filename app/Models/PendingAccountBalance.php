<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingAccountBalance extends Model
{
    //
    protected $fillable = ['amount', "user_id"];
}
