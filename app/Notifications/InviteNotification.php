<?php

namespace App\Notifications;

use App\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InviteNotification extends Notification
{
    use Queueable;

    protected Invite $invite;

    public function __construct(Invite $invite)
    {
        $this->invite = $invite;
    }

    // database only
    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'invite_id'    => $this->invite->id,
            'group_id'     => $this->invite->group_id,
            'sender_id'    => $this->invite->sender_id,
            'recipient_id' => $this->invite->recipient_id,
            'type'         => $this->invite->type,    // 'invite' | 'request'
            'role'         => $this->invite->role ?? 'member',
            'message'      => $this->invite->message ?? null,
            'status'       => $this->invite->status ?? 'pending',
            'meta'         => $this->invite->meta ?? null,
            'created_at'   => now()->toDateTimeString(),
        ];
    }
}
