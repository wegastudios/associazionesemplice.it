<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Database\DatabaseRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Comando unico per le migrazioni di tutti i database, sempre con il ruolo proprietario.
 *
 * - database condiviso (centrale): migrazioni Central (schema core) + Org (app, audit, sites);
 * - database dedicati: solo le migrazioni Org.
 *
 * Le migrazioni stanno nei moduli: app/Modules/<Modulo>/Database/Migrations/{Central,Org}.
 */
#[AsCommand(name: 'saas:migrate')]
final class MigrateAllCommand extends Command
{
    protected $signature = 'saas:migrate
        {--database=* : Solo questi database (shared o nome del dedicato)}
        {--fresh : Cancella gli schemi e riparte da zero (mai in produzione)}
        {--force : Necessario in produzione}';

    protected $description = 'Esegue le migrazioni sul database condiviso e su tutti i database dedicati';

    private const SCHEMAS = ['core', 'app', 'audit', 'sites'];

    public function handle(DatabaseRegistry $registry, DatabaseManager $db): int
    {
        $production = $this->laravel->environment('production');

        if ($production && ! $this->option('force')) {
            $this->error('In produzione serve --force.');

            return self::FAILURE;
        }
        if ($this->option('fresh') && $production) {
            $this->error('--fresh non è ammesso in produzione.');

            return self::FAILURE;
        }

        /** @var list<string> $only */
        $only = (array) $this->option('database');
        $names = $only === [] ? $registry->names() : $only;

        foreach ($names as $name) {
            if (! $registry->has($name)) {
                $this->error("Database sconosciuto: {$name}");

                return self::FAILURE;
            }
        }

        foreach ($names as $name) {
            $connection = $registry->ownerConnection($name);
            $this->components->info("Database {$name}");

            if ($this->option('fresh')) {
                $owner = $db->connection($connection);
                foreach (self::SCHEMAS as $schema) {
                    $owner->statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
                }
                $owner->statement('DROP TABLE IF EXISTS public.migrations');
            }

            $status = $this->call('migrate', [
                '--database' => $connection,
                '--path' => $this->paths($name === DatabaseRegistry::SHARED),
                '--realpath' => true,
                '--force' => true,
            ]);

            $db->purge($connection);

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function paths(bool $central): array
    {
        $kinds = $central ? ['Central', 'Org'] : ['Org'];
        $paths = [];
        foreach ($kinds as $kind) {
            $paths = [...$paths, ...(glob(app_path("Modules/*/Database/Migrations/{$kind}"), GLOB_ONLYDIR) ?: [])];
        }

        return $paths;
    }
}
