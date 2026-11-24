<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Database\DatabaseRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Dice a deploy/remote.sh se il database condiviso è davvero vuoto (mai migrato): nessuna tabella public.migrations,
 * oppure tabella senza righe. Si legge con la connessione del proprietario. Solo in lettura.
 *
 * Il codice di uscita è il messaggio per il rilascio:
 *   0  vuoto, e l'ambiente (non produzione) ammette di migrare da solo prima del controllo di salute: primo rilascio;
 *   1  già in uso: il rilascio resta quello di sempre (controllo di salute, poi migrazioni);
 *   2  non si riesce a leggere il database: il rilascio si ferma;
 *   3  vuoto ma in produzione: potrebbe essere il database sbagliato, il rilascio si ferma e non migra.
 */
#[AsCommand(name: 'saas:db:empty')]
final class DatabaseEmptyCommand extends Command
{
    public const EMPTY = 0;

    public const IN_USE = 1;

    public const UNREADABLE = 2;

    public const EMPTY_IN_PRODUCTION = 3;

    protected $signature = 'saas:db:empty';

    protected $description = 'Dice se il database condiviso è vuoto (mai migrato); usato dal rilascio per il primo deploy';

    public function handle(DatabaseRegistry $registry, DatabaseManager $db): int
    {
        try {
            $owner = $db->connection($registry->ownerConnection(DatabaseRegistry::SHARED));
            $registered = $owner->scalar("SELECT to_regclass('public.migrations') IS NOT NULL");
            $rows = $registered === true ? (int) $owner->table('migrations')->count() : 0;
        } catch (Throwable $e) {
            $this->components->error('Database non leggibile ('.class_basename($e).'): il rilascio non può decidere.');

            return self::UNREADABLE;
        }

        if ($rows > 0) {
            $this->line('database in uso: nessuna scorciatoia, controllo di salute prima delle migrazioni');

            return self::IN_USE;
        }

        if ($this->laravel->environment('production')) {
            $this->components->error('Database vuoto in produzione: potrebbe essere quello sbagliato. Non si migra da soli: controlla DB_* in shared/.env e migra a mano.');

            return self::EMPTY_IN_PRODUCTION;
        }

        $this->line('database vuoto: primo rilascio, si migra prima del controllo');

        return self::EMPTY;
    }
}
