<?php

declare(strict_types=1);

namespace App\Modules\Soci\Jobs;

use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\ThrottledMail;
use App\Modules\Soci\Services\ApplicationService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * L'email a chi ha fatto domanda di adesione: ricevuta, accolta o non accolta. Nel lavoro ci sono solo identificativi:
 * indirizzo e testo si ricavano al momento dell'invio, così nella coda (e tra i lavori falliti) non restano dati personali.
 * Il link di conferma è urgente (qualcuno lo sta aspettando: MailQueue::URGENT), gli altri esiti sono posta massiva.
 */
final class SendApplicationEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Errori veri dopo i quali il lavoro si arrende; le attese (tetti, server di posta fermo) non contano. */
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $orgId,
        public readonly string $applicationId,
        public readonly string $kind,
        public readonly string $orgName,
    ) {
        $this->onQueue($this->urgent() ? MailQueue::URGENT : MailQueue::BULK);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottledMail];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900];
    }

    /** La conferma si riprova finché il link varrebbe; gli altri esiti come tutta la posta massiva. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours($this->urgent() ? ApplicationService::CONFIRM_HOURS : MailQueue::BULK_RETRY_HOURS);
    }

    public function handle(OrgContext $context): void
    {
        $context->run($this->orgId, fn () => app(ApplicationService::class)->sendMail($this->applicationId, $this->kind, $this->orgName));
    }

    private function urgent(): bool
    {
        return $this->kind === 'confirm';
    }
}
