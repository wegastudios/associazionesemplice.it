<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Core\Contracts\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email semplice dell'associazione a un socio o al suo genitore o tutore: promemoria, conferme delle domande. Con un
 * pulsante solo se si indicano testo e indirizzo (la conferma della domanda di adesione).
 */
final class MemberNotification extends Notification
{
    public function __construct(
        public readonly string $subject,
        public readonly string $greeting,
        public readonly string $body,
        public readonly string $orgName,
        public readonly ?string $actionText = null,
        public readonly ?string $actionUrl = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(MailText::plain($this->orgName).' · '.$this->subject)
            ->greeting($this->greeting)
            ->line($this->body);
        if ($this->actionText !== null && $this->actionUrl !== null) {
            $message->action($this->actionText, $this->actionUrl);
        }

        return $message->salutation('A presto, '.MailText::plain($this->orgName));
    }
}
