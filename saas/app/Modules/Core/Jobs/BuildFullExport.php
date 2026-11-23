<?php

declare(strict_types=1);

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Contracts\OrgScopedJob;
use App\Modules\Core\Contracts\RunInOrgContext;
use App\Modules\Core\Export\FullExports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Prepara una copia di sicurezza completa fuori dalla richiesta. Nel lavoro solo gli identificativi. Se la coda lo
 * riprende mentre gira ancora (oltre retry_after), il secondo giro trova la copia già presa e finisce subito.
 */
final class BuildFullExport implements OrgScopedJob, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** Secondi massimi di lavoro; oltre, la copia risulta non riuscita (FullExports::STALE_AFTER). */
    public int $timeout = FullExports::TIMEOUT;

    /**
     * Non per riprovare (un lavoro interrotto resta «in preparazione» fino a STALE_AFTER): la coda sul database lo riprende
     * dopo retry_after (90 s) mentre gira ancora, e quel giro deve finire subito senza contare come fallito.
     */
    public int $tries = 3;

    public function __construct(public readonly string $org, public readonly string $exportId) {}

    public function orgId(): string
    {
        return $this->org;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RunInOrgContext];
    }

    public function handle(FullExports $exports): void
    {
        $exports->run($this->exportId);
    }

    public function failed(Throwable $e): void
    {
        app(FullExports::class)->failedInQueue($this->org, $this->exportId);
    }
}
