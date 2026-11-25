<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Contracts\ShortLocks;
use App\Modules\Core\Database\DatabaseRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

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

    private const SCHEMAS = ['core', 'app', 'audit', 'sites', 'portal'];

    /** Attesa massima della fine del backup, per tutti i database insieme (il rilascio ha 15 minuti), e ogni quanto si ricontrolla. */
    public const DUMP_WAIT_SECONDS = 480;

    public const DUMP_POLL_SECONDS = 10;

    private int $dumpWaitLeft = self::DUMP_WAIT_SECONDS;

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

        // Un database che non riesce non ferma gli altri (ognuno ha le sue migrazioni, e la versione in uso continua a
        // funzionare): si va avanti e alla fine il comando fallisce, così il rilascio non passa alla versione nuova.
        $failed = [];
        foreach ($names as $name) {
            $connection = $registry->ownerConnection($name);
            $this->components->info("Database {$name}");

            try {
                $owner = $db->connection($connection);
                if ($this->option('fresh')) {
                    foreach (self::SCHEMAS as $schema) {
                        $owner->statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
                    }
                    $owner->statement('DROP TABLE IF EXISTS public.migrations');
                } else {
                    $this->waitForDump($owner);
                }

                // Ogni istruzione delle migrazioni aspetta un blocco solo pochi secondi; se scade, la migrazione si annulla
                // e si riprova (quelle già fatte restano fatte).
                $owner->select("SELECT set_config('lock_timeout', ?, false)", [ShortLocks::timeout()]);
                $status = ShortLocks::retry(fn (): int => $this->call('migrate', [
                    '--database' => $connection,
                    '--path' => $this->paths($name === DatabaseRegistry::SHARED),
                    '--realpath' => true,
                    '--force' => true,
                ]));
            } catch (Throwable $e) {
                report($e);
                $status = self::FAILURE;
                // Il log del rilascio è pubblico: lì solo il tipo di errore, il dettaglio nel log dell'applicazione.
                $detail = $this->laravel->environment(['local', 'testing']) ? $e->getMessage() : class_basename($e).' '.$e->getCode();
                $this->components->error("Database {$name}: migrazioni non riuscite ({$detail}).");
            } finally {
                $db->purge($connection);
            }

            if ($status !== self::SUCCESS) {
                $failed[] = $name;
            }
        }

        if ($failed !== []) {
            $this->components->error('Migrazioni non riuscite su: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Il backup orario tiene un blocco leggero su ogni tabella finché dura: una migrazione che cambia una tabella si
     * metterebbe in fila dietro di lui, e tutte le query su quella tabella dietro la migrazione. Si aspetta che finisca,
     * per un tempo massimo; poi si prova comunque (con l'attesa breve dei blocchi).
     */
    private function waitForDump(Connection $owner): void
    {
        if ($this->dumpWaitLeft <= 0 || ! $this->dumpRunning($owner)) {
            return;
        }
        $this->components->warn('Backup in corso su questo database: aspetto che finisca (al massimo '.intdiv($this->dumpWaitLeft, 60).' minuti).');
        do {
            Sleep::for(self::DUMP_POLL_SECONDS)->seconds();
            $this->dumpWaitLeft -= self::DUMP_POLL_SECONDS;
        } while ($this->dumpWaitLeft > 0 && $this->dumpRunning($owner));
    }

    private function dumpRunning(Connection $owner): bool
    {
        return $owner->scalar("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE datname = current_database() AND application_name = 'pg_dump')") === true;
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
