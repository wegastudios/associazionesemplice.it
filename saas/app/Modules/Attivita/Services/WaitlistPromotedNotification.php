<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email al socio (o al genitore o tutore del minore) quando dalla lista d'attesa passa alla prenotazione. */
final class WaitlistPromotedNotification extends Notification
{
    public function __construct(
        public readonly string $greeting,
        public readonly string $body,
        public readonly ?string $payLine,
        public readonly ?string $areaUrl,
        public readonly string $orgName,
        public readonly ?string $donateUrl = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->orgName.' · Si è liberato un posto')->greeting($this->greeting)->line($this->body);
        if ($this->payLine !== null) {
            $mail->line($this->payLine);
        }
        if ($this->areaUrl !== null) {
            $mail->action('Apri l\'area soci', $this->areaUrl);
        }

        if ($this->donateUrl !== null) {
            $mail->line('Vuoi sostenere '.$this->orgName.'? [Fai una donazione]('.$this->donateUrl.').');
        }

        return $mail->salutation('A presto, '.$this->orgName);
    }
}
