<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email semplice dell'associazione a un socio o al suo genitore o tutore: promemoria, conferme delle domande.
 * Parte in coda (chi ha inviato il modulo non aspetta la posta); {@see ReminderService} la manda subito perché lo fa già da un lavoro.
 */
final class MemberNotification extends Notification implements ShouldQueue
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
        public readonly string $subject,
        public readonly string $greeting,
        public readonly string $body,
        public readonly string $orgName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject.' · '.$this->orgName)
            ->greeting($this->greeting)
            ->line($this->body)
            ->salutation('A presto, '.$this->orgName);
    }
}
