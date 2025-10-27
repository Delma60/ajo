<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invite extends Model
{
    protected $fillable = [
        'group_id',
        'sender_id',
        'recipient_id',
        'type',
        'status',
        'role',
        'message',
        'token',
    ];

    protected $with = ['group', 'sender', 'recipient'];

    public function group() { return $this->belongsTo(Group::class); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function recipient() { return $this->belongsTo(User::class, 'recipient_id'); }
}
