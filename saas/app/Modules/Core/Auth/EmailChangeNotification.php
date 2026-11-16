<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Al NUOVO indirizzo: il link per confermare che la casella è di chi l'ha chiesto. L'email del profilo cambia solo
 * quando il link viene aperto (vale 60 minuti, una persona alla volta).
 */
final class EmailChangeNotification extends Notification
{
    public function __construct(public readonly string $name, public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Conferma il nuovo indirizzo email')
            ->greeting("Ciao {$this->name},")
            ->line('hai chiesto di usare questo indirizzo per accedere al gestionale di Associazione Semplice.')
            ->line('Per confermare apri il link: vale 60 minuti. Finché non lo apri, l\'email di accesso non cambia.')
            ->action('Conferma l\'indirizzo', $this->url)
            ->line('Se non sei stato tu, ignora questo messaggio: non succede nulla.');
    }
}
