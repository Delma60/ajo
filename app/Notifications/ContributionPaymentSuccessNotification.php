<?php

namespace App\Notifications;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContributionPaymentSuccessNotification extends Notification
{
    use Queueable;

    protected $transaction; // Transaction model or null on failure
    protected $group; // Group model (optional)
    protected $status; // 'success'|'pending'|'failed'
    protected $extra; // array

    public function __construct($transaction = null, $group = null, string $status = 'success', array $extra = [])
    {
        $this->transaction = $transaction;
        $this->group = $group;
        $this->status = $status;
        $this->extra = $extra;
    }

    public function via($notifiable)
    {
        // database always; broadcast optional realtime delivery
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable)
    {
        $tx = $this->transaction;
        return [
            'type' =>  $this->status,
            'title' => $this->status === 'success' ? 'Contribution successful' : ($this->status === 'pending' ? 'Contribution pending' : 'Contribution failed'),
            'body' => $this->status === 'success'
                ? sprintf('You contributed %s to %s', number_format($tx->amount, 2), $this->group->name ?? 'the group')
                : ($this->status === 'pending' ? 'Your contribution is pending. We will notify you when it completes.' : ('Payment failed: '.($this->extra['error'] ?? 'An error occurred'))),
            'transaction_id' => $tx ? $tx->id : null,
            'reference' => $tx ? ($tx->reference ?? $tx->uuid ?? null) : null,
            'amount' => $tx ? (float) $tx->amount : ($this->extra['amount'] ?? null),
            'group_id' => $this->group ? $this->group->id : null,
            'link' => $this->group ? "/groups/{$this->group->id}" : null,
            'meta' => $this->extra,
        ];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    // optional channel
    public function broadcastOn()
    {
        return new PrivateChannel('users.' . $this->getNotifiableId());
    }

    protected function getNotifiableId()
    {
        // not all notifiables use id property - adapt if necessary
        return $this->group?->owner?->id ?? null;
    }
}
