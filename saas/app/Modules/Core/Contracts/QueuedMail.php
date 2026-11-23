<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as Notifications;

/**
 * Un messaggio già pronto (email, notifica o testo semplice) da mandare con i tetti della posta (MailQueue, ThrottledMail).
 * Di solito passa da MailQueue::sendNowOrQueue: parte subito se c'è quota, altrimenti aspetta in coda.
 *
 * Il messaggio può contenere un link o un codice d'accesso: il lavoro è cifrato (ShouldBeEncrypted), così né la coda né i
 * lavori falliti li hanno in chiaro, e si riprova solo finché il link o il codice valgono ($until).
 */
final class QueuedMail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Errori veri dopo i quali il lavoro si arrende; le attese (tetti, server di posta fermo) non contano. */
    public int $maxExceptions = 3;

    /**
     * @param  list<string>|string|null  $to
     * @param  array{subject: string, body: string}|null  $raw
     */
    private function __construct(
        private readonly int $until,
        private readonly array|string|null $to = null,
        private readonly ?Mailable $mailable = null,
        private readonly ?object $notifiable = null,
        private readonly ?Notification $notification = null,
        private readonly ?array $raw = null,
    ) {}

    /** @param  list<string>|string  $to */
    public static function mailable(array|string $to, Mailable $mailable, string $queue, DateTimeInterface $until): self
    {
        return (new self($until->getTimestamp(), to: $to, mailable: $mailable))->onQueue($queue);
    }

    /** $notifiable: un utente o Notification::route('mail', ...). */
    public static function notification(object $notifiable, Notification $notification, string $queue, DateTimeInterface $until): self
    {
        return (new self($until->getTimestamp(), notifiable: $notifiable, notification: $notification))->onQueue($queue);
    }

    /** Testo semplice, come Mail::raw. */
    public static function raw(string $to, string $subject, string $body, string $queue, DateTimeInterface $until): self
    {
        return (new self($until->getTimestamp(), to: $to, raw: ['subject' => $subject, 'body' => $body]))->onQueue($queue);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottledMail];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->setTimestamp($this->until);
    }

    public function handle(): void
    {
        if ($this->mailable !== null && $this->to !== null) {
            Mail::to($this->to)->send($this->mailable);
        } elseif ($this->notifiable !== null && $this->notification !== null) {
            Notifications::sendNow($this->notifiable, $this->notification);
        } elseif ($this->raw !== null && is_string($this->to)) {
            $raw = $this->raw;
            $to = $this->to;
            Mail::raw($raw['body'], static function (Message $message) use ($raw, $to): void {
                $message->to($to)->subject($raw['subject']);
            });
        }
    }
}
