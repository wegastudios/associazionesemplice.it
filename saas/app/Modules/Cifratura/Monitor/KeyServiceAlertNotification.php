<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Monitor;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avviso all'amministrazione: il servizio chiavi non va (una volta per guasto) o è tornato a funzionare. */
final class KeyServiceAlertNotification extends Notification
{
    public function __construct(
        public readonly bool $down,
        public readonly string $since,
        public readonly ?string $reason = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->down) {
            return (new MailMessage)
                ->subject('Associazione Semplice · Servizio chiavi non disponibile')
                ->greeting('Ciao,')
                ->line("dal {$this->since} il controllo del servizio chiavi non va: ".($this->reason ?? 'motivo non indicato').'.')
                ->line('Finché non torna, i dati cifrati delle associazioni non si aprono (per chi non li ha già in memoria).')
                ->line('Controlla l\'account del servizio chiavi (sottodominio, certificato, config.ini) e il registro del servizio.')
                ->line('Non arrivano altri avvisi per questo guasto: ne arriva uno quando il servizio torna a funzionare.');
        }

        return (new MailMessage)
            ->subject('Associazione Semplice · Servizio chiavi di nuovo in funzione')
            ->greeting('Ciao,')
            ->line("il servizio chiavi risponde di nuovo correttamente (guasto iniziato il {$this->since}).");
    }
}
