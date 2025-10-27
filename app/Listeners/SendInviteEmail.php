<?php

namespace App\Listeners;

use App\Events\InviteCreated;
use App\Mail\InviteMailable;
use App\Notifications\InviteNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendInviteEmail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(InviteCreated $event): void
    {
        $invite = $event->invite;

        // send mailable to the recipient if they have email
        $recipient = $invite->recipient;
        if ($recipient && $recipient->email) {
            Mail::to($recipient->email)
                ->send(new InviteMailable($invite));
        }

        // also create an in-app database notification (if user model uses Notifiable)
        if ($recipient) {
            $recipient->notify(new InviteNotification($invite));
        }
    }

    public function failed(InviteCreated $event, \Throwable $exception)
    {
        // optional: log or handle failures
        Log::error('SendInviteEmail failed', [
            'invite_id' => $event->invite->id,
            'error' => $exception->getMessage()
        ]);
    }
}
