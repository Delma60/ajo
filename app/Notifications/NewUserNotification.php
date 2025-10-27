<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewUserNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable)
    {
        // send to database and email
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable)
    {
        $name = $this->user->name ?? 'Member';
        return (new MailMessage)
            ->subject('Welcome to CoopThrift')
            ->greeting("Hello {$name},")
            ->line('Thank you for creating an account at CoopThrift. You can now join or create Ajo groups, top up your wallet, and start contributing with your coop.')
            ->action('Go to app', url('/'))
            ->line('If you did not create this account, please contact support.');
    }

    /**
     * Get the array representation of the notification for database storage.
     */
    public function toDatabase($notifiable)
    {
        return [
            'type' => 'welcome',
            'title' => 'Welcome to CoopThrift',
            'body' => 'Thanks for joining CoopThrift — your account is ready.',
            'user_id' => $this->user->id ?? null,
            'extra' => [
                'name' => $this->user->name ?? null,
            ],
        ];
    }
}
