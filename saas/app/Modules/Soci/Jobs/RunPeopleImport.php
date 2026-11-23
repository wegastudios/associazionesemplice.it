<?php

declare(strict_types=1);

namespace App\Modules\Soci\Jobs;

use App\Modules\Core\Contracts\ImportJobs;
use App\Modules\Core\Contracts\JobQueues;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgScopedJob;
use App\Modules\Core\Contracts\RunInOrgContext;
use App\Modules\Soci\Services\PeopleImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Un giro dell'import soci (H10): blocchi per saas.imports.seconds_per_run secondi, poi si rimette in coda e riparte dal
 * blocco successivo. Nel lavoro ci sono solo gli identificativi: l'elenco è cifrato nella tabella degli import.
 */
final class RunPeopleImport implements OrgScopedJob, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** Un blocco che fallisce si annulla da solo e si riprova; dopo tre tentativi l'import resta fermo e si riprende a mano. */
    public int $tries = 3;

    /** Oltre il giro di 40 secondi c'è al massimo un blocco (e i tutori alla fine). */
    public int $timeout = 600;

    public function __construct(
        public readonly string $orgId,
        public readonly string $importId,
    ) {
        // Fino a 600 s: coda dei lavori lunghi, che non riprende un giro mentre gira ancora (JobQueues).
        $this->onQueue(JobQueues::LONG);
    }

    public function orgId(): string
    {
        return $this->orgId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RunInOrgContext];
    }

    public function handle(PeopleImportService $import): void
    {
        if (! $import->runQueued($this->importId, config()->integer('saas.imports.block', 100), config()->integer('saas.imports.seconds_per_run', 40))) {
            self::dispatch($this->orgId, $this->importId);
        }
    }

    public function failed(Throwable $e): void
    {
        app(OrgContext::class)->run($this->orgId, fn () => app(ImportJobs::class)->fail($this->importId));
    }
}
