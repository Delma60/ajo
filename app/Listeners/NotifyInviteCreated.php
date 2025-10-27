<?php

namespace App\Listeners;

use App\Events\InviteCreated;
use App\Notifications\InviteNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class NotifyInviteCreated implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(InviteCreated $event): void
    {
        $invite = $event->invite;
        $recipient = $invite->recipient;

        if (! $recipient) {
            Log::warning('InviteCreated missing recipient', ['invite_id' => $invite->id]);
            return;
        }

        // Create DB notification only
        $recipient->notify(new InviteNotification($invite));
    }

    public function failed(InviteCreated $event, \Throwable $exception)
    {
        Log::error('NotifyInviteCreated failed', [
            'invite_id' => $event->invite->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
