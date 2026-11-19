<?php

declare(strict_types=1);

namespace App\Modules\Portale\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avvisi su dati e accesso, mandati dal gestionale perché è lui a eseguire sospensione e cancellazione: fine della prova,
 * sospensione, promemoria prima dell'eliminazione dei dati. Gli avvisi sui soldi (rinnovo, pagamento non riuscito,
 * scadenza vicina, disdetta) li manda il portale. Nessun dato dei soci nel messaggio.
 *
 * I giorni sono quelli che il codice esegue: la conservazione dura `$keepDays` giorni dalla scadenza e `$lastDay` è
 * l'ultimo giorno in cui i dati ci sono ancora; l'eliminazione avviene dopo.
 */
final class OrgLifecycleNotification extends Notification
{
    /**
     * @param  int  $days  giorni alla fine della prova (negativo dopo la fine); non usato dagli avvisi sui dati
     * @param  int  $keepDays  giorni di conservazione dalla scadenza
     * @param  CarbonImmutable  $lastDay  ultimo giorno in cui i dati si conservano
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $orgName,
        public readonly int $days,
        public readonly string $actionUrl,
        public readonly int $keepDays,
        public readonly CarbonImmutable $lastDay,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $org = $this->orgName;
        $last = $this->lastDay->format('d/m/Y');
        $left = (int) CarbonImmutable::today()->diffInDays($this->lastDay, false);
        $in = self::when($left);
        $mail = (new MailMessage)->greeting('Ciao,');

        [$subject, $lines, $button] = match (true) {
            $this->kind === 'suspended' => [
                "{$org} · Gestionale sospeso",
                [
                    "il gestionale di {$org} è sospeso perché l'abbonamento è scaduto.",
                    "I dati restano conservati per {$this->keepDays} giorni dalla scadenza, fino al {$last}; poi vengono eliminati per sempre.",
                    "Se rinnovi entro il {$last} ritrovi tutti i dati come li hai lasciati.",
                ],
                'Riattiva il gestionale',
            ],
            str_starts_with($this->kind, 'purge') => [
                $left <= 1 ? "{$org} · Ultimo giorno per riattivare: {$in}" : "{$org} · I dati vengono eliminati dopo il {$last}",
                [
                    "i dati di {$org} saranno eliminati per sempre dopo il {$last} ({$in}).",
                    "L'eliminazione non si può annullare. Fino al {$last} puoi riattivare il gestionale rinnovando l'abbonamento.",
                ],
                'Riattiva il gestionale',
            ],
            $this->days >= 0 => [
                "{$org} · La prova finisce ".self::when($this->days),
                [
                    "la prova gratuita di {$org} finisce ".self::when($this->days).'.',
                    "Scegli un piano per continuare: senza un piano i dati della prova saranno eliminati per sempre dopo il {$last}.",
                ],
                'Scegli un piano',
            ],
            default => [
                "{$org} · La prova è finita",
                [
                    "la prova gratuita di {$org} è finita.",
                    "Scegli un piano per continuare: i dati della prova saranno eliminati per sempre dopo il {$last} ({$in}).",
                ],
                'Scegli un piano',
            ],
        };
        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail->subject($subject)->action($button, $this->actionUrl);
    }

    /** «oggi», «domani» o «tra N giorni»: il numero ha sempre la forma giusta. */
    private static function when(int $days): string
    {
        return match (true) {
            $days <= 0 => 'oggi',
            $days === 1 => 'domani',
            default => "tra {$days} giorni",
        };
    }
}
