<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Jobs;

use App\Modules\Attivita\Services\SessionService;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * L'avviso a chi passa dalla lista d'attesa alla prenotazione. Nel lavoro ci sono solo identificativi: indirizzo e
 * testo si ricavano al momento dell'invio, così nella coda (e tra i lavori falliti) non restano dati personali.
 */
final class SendPromotionEmail implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $orgId,
        public readonly string $sessionId,
        public readonly string $personId,
        public readonly bool $toPay,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900];
    }

    public function handle(OrgContext $context): void
    {
        $context->run($this->orgId, fn () => app(SessionService::class)->sendPromotion($this->sessionId, $this->personId, $this->toPay));
    }
}
