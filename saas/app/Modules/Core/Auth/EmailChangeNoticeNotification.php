<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use App\Modules\Core\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Al VECCHIO indirizzo: è stato chiesto di cambiarlo. Il nuovo indirizzo è mostrato in parte. */
final class EmailChangeNoticeNotification extends Notification
{
    public function __construct(public readonly string $maskedNew) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Associazione Semplice · Richiesta di cambio email')
            ->greeting("Ciao {$notifiable->name},")
            ->line("è stato chiesto di cambiare l'email del tuo accesso in {$this->maskedNew}.")
            ->line('L\'email non cambia finché il nuovo indirizzo non viene confermato.')
            ->line('Se non l\'hai chiesto tu, cambia subito la password dal tuo profilo.');
    }

    /** «m***@e***.it»: abbastanza per riconoscerlo, non per ricostruirlo. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $dot = strrpos($domain, '.');
        $host = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($local, 0, 1).'***@'.mb_substr($host, 0, 1).'***'.$tld;
    }
}
