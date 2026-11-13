<?php

declare(strict_types=1);

namespace App\Modules\Portale\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avvisi sul ciclo di vita dell'abbonamento: scadenza, sospensione, cancellazione dei dati, fine della prova.
 * Nessun dato dei soci nel messaggio. I testi sono una prima stesura da rivedere con l'audit.
 */
final class OrgLifecycleNotification extends Notification
{
    /** @param  int  $days  giorni che mancano alla scadenza o alla cancellazione (secondo il tipo) */
    public function __construct(
        public readonly string $kind,
        public readonly string $orgName,
        public readonly int $days,
        public readonly string $actionUrl,
        public readonly int $discountPercent = 0,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = $this->days;
        $mail = (new MailMessage)->greeting('Buongiorno,');
        [$subject, $lines, $button] = match (true) {
            str_starts_with($this->kind, 'expiry') => [
                "L'abbonamento di {$this->orgName} sta per scadere",
                ["l'abbonamento di {$this->orgName} scade tra {$days} giorni e non risulta un pagamento in corso.", 'Dopo la scadenza il gestionale si sospende e non potrai più esportare i tuoi dati: se vuoi conservarli, esportali adesso.'],
                'Gestisci l\'abbonamento',
            ],
            $this->kind === 'suspended' => [
                "{$this->orgName}: gestionale sospeso",
                ["l'abbonamento di {$this->orgName} è scaduto e il gestionale è sospeso.", "I dati restano conservati per {$days} giorni; poi vengono cancellati in modo definitivo. Per riattivare tutto basta rinnovare: nulla va perso."],
                'Riattiva il gestionale',
            ],
            str_starts_with($this->kind, 'purge') => [
                $days <= 1 ? "{$this->orgName}: domani cancelliamo i tuoi dati" : "{$this->orgName}: i dati si cancellano tra {$days} giorni",
                [$days <= 1 ? "domani i dati di {$this->orgName} verranno cancellati in modo definitivo." : "tra {$days} giorni i dati di {$this->orgName} verranno cancellati in modo definitivo.", 'La cancellazione non si può annullare. Puoi riattivare il gestionale in qualsiasi momento fino a quel giorno.'],
                'Riattiva il gestionale',
            ],
            default => [
                $days >= 0 ? "La prova di {$this->orgName} finisce tra {$days} giorni" : "La prova di {$this->orgName} è finita",
                $days >= 0
                    ? ["la prova gratuita di {$this->orgName} finisce tra {$days} giorni.", 'Scegli un piano per continuare: se non lo fai, i dati della prova vengono cancellati pochi giorni dopo la fine.']
                    : ["la prova gratuita di {$this->orgName} è finita.", 'Scegli un piano per continuare: i dati della prova stanno per essere cancellati.'],
                'Scegli un piano',
            ],
        };
        foreach ($lines as $line) {
            $mail->line($line);
        }
        if ($this->discountPercent > 0 && str_starts_with($this->kind, 'trial')) {
            $mail->line("Per te c'è uno sconto del {$this->discountPercent}% sul primo pagamento, finché i dati non vengono cancellati.");
        }

        return $mail->subject($subject)->action($button, $this->actionUrl);
    }
}
