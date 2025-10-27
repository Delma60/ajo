<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use App\Services\NotificationService;

class TransactionNotification extends Notification
{
    use Queueable;

    protected $transaction;
    protected $type; // 'success'|'failed'|'pending'
    protected $extra;

    public function __construct($transaction, string $type = 'success', array $extra = [])
    {
        $this->transaction = $transaction;
        $this->type = $type;
        $this->extra = $extra;
    }

    public function via($notifiable)
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable)
    {
        return NotificationService::buildTransactionPayload($this->transaction, $this->type, $this->extra);
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    public function broadcastOn()
    {
        return new \Illuminate\Broadcasting\PrivateChannel('users.' . ($this->transaction->user_id ?? '')); 
    }
}
