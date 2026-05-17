<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpoPushToken extends Model
{
    //
    protected $fillable = ['user_id', 'token', 'platform'];
}
