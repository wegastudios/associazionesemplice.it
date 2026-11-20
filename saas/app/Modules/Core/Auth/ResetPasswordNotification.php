<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use App\Modules\Core\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

final class ResetPasswordNotification extends Notification
{
    public function __construct(#[SensitiveParameter] public readonly string $token) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Associazione Semplice · Reimposta la password')
            ->greeting("Ciao {$notifiable->name},")
            ->line('hai chiesto di reimpostare la password del tuo accesso.')
            ->action('Reimposta la password', AppLink::route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line('Il link vale 60 minuti. Se non hai chiesto tu di reimpostare la password, ignora questo messaggio.');
    }
}
