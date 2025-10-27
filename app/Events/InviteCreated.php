<?php

namespace App\Events;

use App\Models\Invite;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InviteCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Invite $invite;

    /**
     * Create a new event instance.
     */
    public function __construct(Invite $invite)
    {
        $this->invite = $invite;
    }
}
