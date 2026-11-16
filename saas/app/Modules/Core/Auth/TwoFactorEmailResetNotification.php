<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Il link per togliere la verifica in due passaggi a chi ha perso telefono e codici di recupero (60 minuti, una volta sola). */
final class TwoFactorEmailResetNotification extends Notification
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
            ->subject('Togli la verifica in due passaggi')
            ->greeting("Ciao {$this->name},")
            ->line('hai chiesto di togliere la verifica in due passaggi perché non hai più il telefono né i codici di recupero.')
            ->line('Apri il link entro 60 minuti: la verifica viene tolta e al prossimo accesso dovrai riattivarla subito, con il telefono nuovo.')
            ->action('Togli la verifica', $this->url)
            ->line('Se non sei stato tu, ignora questo messaggio: non succede nulla e il tuo accesso resta protetto.');
    }
}
