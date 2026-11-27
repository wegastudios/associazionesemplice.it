<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A chi lavora in un'associazione e non ha la verifica in due passaggi: da ora l'associazione la chiede. */
final class OrgTwoFactorRequiredNotification extends Notification
{
    public function __construct(public readonly string $name, public readonly string $orgName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = config('app.url');
        $url = rtrim(is_string($base) && $base !== '' ? $base : 'https://app.associazionesemplice.it', '/').'/account/verifica-in-due-passaggi';

        return (new MailMessage)
            ->subject("Associazione Semplice · {$this->orgName} chiede la verifica in due passaggi")
            ->greeting("Ciao {$this->name},")
            ->line("da ora l'associazione «{$this->orgName}» chiede la verifica in due passaggi a chi lavora nel gestionale.")
            ->line('A ogni accesso, oltre alla password, inserirai un codice generato da un\'app sul telefono (per esempio Google Authenticator o Microsoft Authenticator). Al prossimo accesso ti verrà chiesto di attivarla: bastano pochi minuti.')
            ->action('Attiva la verifica', $url)
            ->line('Se non lavori più per questa associazione puoi ignorare questa email.');
    }
}
