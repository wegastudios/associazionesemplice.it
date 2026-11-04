<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email semplice dell'associazione a un socio o al suo genitore o tutore: promemoria, conferme delle domande. */
final class MemberNotification extends Notification
{
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
