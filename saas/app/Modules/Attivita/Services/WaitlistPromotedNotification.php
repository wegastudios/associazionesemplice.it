<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email al socio (o al genitore o tutore del minore) quando dalla lista d'attesa passa alla prenotazione. Parte in coda. */
final class WaitlistPromotedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Tentativi se la posta non risponde, con attese crescenti. */
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900];
    }

    public function __construct(
        public readonly string $greeting,
        public readonly string $body,
        public readonly ?string $payLine,
        public readonly ?string $areaUrl,
        public readonly string $orgName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Si è liberato un posto · '.$this->orgName)->greeting($this->greeting)->line($this->body);
        if ($this->payLine !== null) {
            $mail->line($this->payLine);
        }
        if ($this->areaUrl !== null) {
            $mail->action('Apri l\'area soci', $this->areaUrl);
        }

        return $mail->salutation('A presto, '.$this->orgName);
    }
}
