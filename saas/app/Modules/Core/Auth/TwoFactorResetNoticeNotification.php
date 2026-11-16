<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avviso a chi ha l'account: la verifica in due passaggi è stata tolta tramite il link ricevuto per email. */
final class TwoFactorResetNoticeNotification extends Notification
{
    public function __construct(public readonly string $name) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('La verifica in due passaggi è stata tolta')
            ->greeting("Ciao {$this->name},")
            ->line('la verifica in due passaggi del tuo accesso è stata tolta tramite un link ricevuto per email.')
            ->line('Se non sei stato tu, cambia subito la password.');
    }
}
