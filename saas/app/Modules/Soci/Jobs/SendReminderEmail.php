<?php

declare(strict_types=1);

namespace App\Modules\Soci\Jobs;

use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\ThrottledMail;
use App\Modules\Soci\Services\ReminderService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Un promemoria da mandare: un lavoro per destinatario. Nel lavoro non c'è nessun dato personale (solo gli identificativi):
 * indirizzo e testo si ricavano al momento dell'invio. Posta massiva (MailQueue::BULK): tetto della posta massiva più un
 * limite per associazione (saas.reminders.per_org_per_minute); oltre il limite il lavoro riparte più tardi.
 */
final class SendReminderEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Errori veri dopo i quali il lavoro si arrende; le attese (tetti, server di posta fermo) non contano. */
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $orgId,
        public readonly string $personId,
        public readonly string $kind,
        public readonly string $until,
        public readonly string $today,
    ) {
        $this->onQueue(MailQueue::BULK);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottledMail('reminder-mail')];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900, 3600];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(MailQueue::BULK_RETRY_HOURS);
    }

    public function handle(OrgContext $context): void
    {
        $context->run($this->orgId, fn () => app(ReminderService::class)->sendQueued($this->personId, $this->kind, $this->until, $this->today));
    }

    /** Se alla fine non parte, si toglie la prenotazione: il giro di domani ci riprova (finché il promemoria vale). */
    public function failed(Throwable $e): void
    {
        app(OrgContext::class)->run($this->orgId, fn () => app(ReminderService::class)->releaseClaim($this->personId, $this->kind, $this->until));
    }
}
