<?php

declare(strict_types=1);

namespace App\Modules\Soci\Jobs;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Services\ReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Un promemoria da mandare: un lavoro per destinatario. Nel lavoro non c'è nessun dato personale (solo gli identificativi):
 * indirizzo e testo si ricavano al momento dell'invio. La frequenza è limitata per associazione e in totale
 * (saas.reminders.per_org_per_minute e per_minute); oltre il limite il lavoro riparte più tardi.
 */
final class SendReminderEmail implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    /** Errori veri (posta non raggiungibile) dopo i quali il lavoro si arrende; le attese per il limite non contano. */
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $orgId,
        public readonly string $personId,
        public readonly string $kind,
        public readonly string $until,
        public readonly string $today,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('reminder-mail')];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900, 3600];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
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
