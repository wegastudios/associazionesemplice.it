<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Demo\DemoEnvironment;
use App\Modules\Core\Demo\DemoResetGuard;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Azzera l'ambiente demo e lo ricrea con dati finti (docs/PIATTAFORMA.md §6). Cancella TUTTO il database
 * dell'ambiente: parte solo dove SAAS_DEMO è acceso E APP_ENV è «demo». Ogni notte alle 03:30.
 */
#[AsCommand(name: 'saas:demo:reset')]
final class DemoResetCommand extends Command
{
    protected $signature = 'saas:demo:reset';

    protected $description = 'Azzera la demo e la ricrea con dati finti (solo nell\'ambiente demo)';

    public function handle(DemoEnvironment $demo): int
    {
        if (! $demo->enabled()) {
            $this->error('La demo non è attiva in questo ambiente (servono SAAS_DEMO=true e APP_ENV=demo).');

            return self::FAILURE;
        }

        // Prima di cancellare: database atteso, nessuna associazione estranea, cartella della demo.
        $refusal = DemoResetGuard::check();
        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $status = $this->call('saas:migrate', ['--fresh' => true, '--database' => ['shared'], '--force' => true]);
        if ($status !== self::SUCCESS) {
            return self::FAILURE;
        }

        // Il catalogo si allinea alla configurazione qui, non alla prima lettura.
        $this->call('saas:catalog:align');

        // I file cifrati dell'associazione precedente non servono più: senza questa pulizia crescono a ogni notte.
        $root = config('filesystems.disks.org_files.root');
        $marker = config('saas.demo.path_marker');
        $removed = DemoResetGuard::clearFiles(is_string($root) ? $root : '', is_string($marker) ? $marker : 'saas-demo');
        if ($removed === null) {
            $this->warn('Cartella dei file non toccata: non sta nella cartella della demo.');
        } else {
            $this->line("File della demo precedente tolti: {$removed}.");
        }

        // Niente di ciò che i visitatori hanno scritto resta oltre la notte: log, file temporanei e cache su disco.
        $logs = DemoResetGuard::clearLogs(storage_path('logs'), is_string($marker) ? $marker : 'saas-demo');
        $tmp = DemoResetGuard::clearFiles(storage_path('app/tmp'), is_string($marker) ? $marker : 'saas-demo');
        $cache = DemoResetGuard::clearFiles(storage_path('framework/cache/data'), is_string($marker) ? $marker : 'saas-demo');
        $this->line('Log, file temporanei e cache su disco tolti: '.(($logs ?? 0) + ($tmp ?? 0) + ($cache ?? 0)).'.');

        try {
            $org = $demo->seed();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Demo ricreata: {$org->name} ({$org->slug}).");

        return self::SUCCESS;
    }
}
