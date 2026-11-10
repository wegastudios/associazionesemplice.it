<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Demo\DemoEnvironment;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Azzera l'ambiente demo e lo ricrea con dati finti (docs/PIATTAFORMA.md §6). Cancella TUTTO il database
 * dell'ambiente: parte solo dove SAAS_DEMO è acceso e l'ambiente non è la produzione. Ogni notte alle 03:30.
 */
#[AsCommand(name: 'saas:demo:reset')]
final class DemoResetCommand extends Command
{
    protected $signature = 'saas:demo:reset';

    protected $description = 'Azzera la demo e la ricrea con dati finti (solo nell\'ambiente demo)';

    public function handle(DemoEnvironment $demo): int
    {
        if (! $demo->enabled()) {
            $this->error('La demo non è attiva in questo ambiente (SAAS_DEMO=true, mai in produzione).');

            return self::FAILURE;
        }

        $status = $this->call('saas:migrate', ['--fresh' => true, '--database' => ['shared'], '--force' => true]);
        if ($status !== self::SUCCESS) {
            return self::FAILURE;
        }

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
