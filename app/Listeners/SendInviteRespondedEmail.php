<?php

namespace App\Listeners;

use App\Events\InviteResponded;
use App\Mail\InviteRespondedMailable;
use App\Notifications\InviteNotification;
use App\Notifications\InviteRespondedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendInviteRespondedEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(InviteResponded $event): void
    {
        $invite = $event->invite;

        // Notify the original sender (could be admin or a user who requested)
        $sender = $invite->sender;
        if ($sender && $sender->email) {
            // Mail::to($sender->email)->send(new InviteNotification($invite));
        }

        // create in-app notification for sender
        if ($sender) {
            // $sender->notify(new InviteRespondedNotification($invite));
        }
    }

    public function failed(InviteResponded $event, \Throwable $exception)
    {
        Log::error('SendInviteRespondedEmail failed', [
            'invite_id' => $event->invite->id,
            'error' => $exception->getMessage()
        ]);
    }
}
