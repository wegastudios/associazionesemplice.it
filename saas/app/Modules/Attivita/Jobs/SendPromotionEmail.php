<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Jobs;

use App\Modules\Attivita\Services\SessionService;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\ThrottledMail;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * L'avviso a chi passa dalla lista d'attesa alla prenotazione. Nel lavoro ci sono solo identificativi: indirizzo e
 * testo si ricavano al momento dell'invio, così nella coda (e tra i lavori falliti) non restano dati personali.
 * Posta massiva (MailQueue::BULK).
 */
final class SendPromotionEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Errori veri dopo i quali il lavoro si arrende; le attese (tetti, server di posta fermo) non contano. */
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $orgId,
        public readonly string $sessionId,
        public readonly string $personId,
        public readonly bool $toPay,
    ) {
        $this->onQueue(MailQueue::BULK);
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

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(MailQueue::BULK_RETRY_HOURS);
    }

    public function handle(OrgContext $context): void
    {
        $context->run($this->orgId, fn () => app(SessionService::class)->sendPromotion($this->sessionId, $this->personId, $this->toPay));
    }
}
