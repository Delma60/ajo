<?php

namespace App\Mail;

use App\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InviteMailable extends Mailable
{
    use Queueable, SerializesModels;

    public Invite $invite;

    /**
     * Create a new message instance.
     */
    public function __construct(Invite $invite)
    {
        $this->invite = $invite;
    }

    public function build()
    {
        $subject = $this->invite->type === 'invite'
            ? "{$this->invite->sender->name} invited you to join {$this->invite->group->name}"
            : "Request received for {$this->invite->group->name}";

        return $this->subject($subject)
                    ->markdown('emails.invite')
                    ->with([
                        'invite' => $this->invite,
                        'group' => $this->invite->group,
                        'sender' => $this->invite->sender,
                        'token' => $this->invite->token,
                    ]);
    }
}
