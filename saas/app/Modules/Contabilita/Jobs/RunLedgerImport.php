<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Jobs;

use App\Modules\Contabilita\Import\LedgerImportService;
use App\Modules\Core\Contracts\ImportJobs;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgScopedJob;
use App\Modules\Core\Contracts\RunInOrgContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Un giro dell'import della prima nota (H11): blocchi per saas.imports.seconds_per_run secondi, poi si rimette in coda e
 * riparte dal blocco successivo. Nel lavoro ci sono solo gli identificativi: l'elenco è cifrato nella tabella degli import.
 */
final class RunLedgerImport implements OrgScopedJob, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** Un blocco che fallisce si annulla da solo e si riprova; dopo tre tentativi l'import resta fermo e si riprende a mano. */
    public int $tries = 3;

    /** Oltre il giro di 40 secondi c'è al massimo un blocco. */
    public int $timeout = 600;

    public function __construct(
        public readonly string $orgId,
        public readonly string $importId,
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

    public function handle(LedgerImportService $import): void
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
