<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Jobs;

use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgScopedJob;
use App\Modules\Core\Contracts\RunInOrgContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Registra l'incasso di un evento del webhook dei pagamenti (H14): prima nota, ricevute, stato del pagamento, registro
 * azioni ed email (in coda a parte). OnlinePayments::settle è una volta sola: un tentativo ripetuto, anche dopo
 * un'interruzione a metà, non registra due volte. Nel lavoro ci sono solo gli identificativi.
 */
final class SettleOnlinePayment implements OrgScopedJob, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 6;

    public function __construct(
        public readonly string $orgId,
        public readonly string $eventId,
    ) {}

    public function orgId(): string
    {
        return $this->orgId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RunInOrgContext];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(OnlinePayments $payments): void
    {
        $payments->settleEvent($this->eventId);
    }

    /** Dopo l'ultimo tentativo: l'evento resta «failed» nel registro; se il gestore lo rimanda torna in coda. */
    public function failed(Throwable $e): void
    {
        app(OrgContext::class)->run($this->orgId, fn () => app(OnlinePayments::class)->eventFailed($this->eventId));
    }
}
