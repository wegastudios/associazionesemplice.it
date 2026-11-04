<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Aiuti per le migrazioni delle tabelle di un'associazione. Ogni tabella di app, audit e sites passa da
 * isolate() e concede al ruolo dell'applicazione solo i permessi che le servono.
 *
 * Le migrazioni girano con la connessione del proprietario; il ruolo dell'applicazione da autorizzare è
 * scritto nella sua configurazione (`app_role`).
 */
final class OrgSchema
{
    private const TABLE_PATTERN = '/^(app|audit|sites)\.[a-z][a-z0-9_]*$/';

    private const PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    /**
     * Row-Level Security forzata (vale anche per il proprietario) con la policy sull'associazione corrente.
     * Se manca `app.org_id` non si vede e non si scrive nessuna riga, senza errori.
     */
    public static function isolate(string $table, string $orgColumn = 'org_id'): void
    {
        self::assertTable($table);
        self::secure(DB::connection(), $table, $orgColumn);
    }

    /**
     * RLS forzata e policy su una tabella, con la connessione data (anche fuori dalle migrazioni: partizioni).
     * In lettura passa anche il ruolo dei backup (sola lettura, nessun permesso di scrittura): pg_dump deve vedere tutte
     * le righe e su cPanel non esiste un ruolo con BYPASSRLS.
     */
    public static function secure(ConnectionInterface $connection, string $table, string $orgColumn = 'org_id'): void
    {
        self::assertTable($table);
        [$using, $check] = self::conditions($connection, $orgColumn);

        $connection->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        $connection->statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        $connection->statement("CREATE POLICY org_isolation ON {$table} USING ({$using}) WITH CHECK ({$check})");
    }

    /** @return array{0: string, 1: string} condizione di lettura e di scrittura */
    public static function conditions(ConnectionInterface $connection, string $orgColumn = 'org_id'): array
    {
        if (preg_match('/^[a-z_]+$/', $orgColumn) !== 1) {
            throw new InvalidArgumentException('Colonna non valida.');
        }
        $check = "{$orgColumn} = NULLIF(current_setting('app.org_id', true), '')::uuid";
        $backup = self::role($connection, 'backup_role');

        return ["{$check} OR current_user = '{$backup}'", $check];
    }

    /** Ruolo dei backup, già quotato per l'SQL. */
    public static function backupRole(): string
    {
        return '"'.self::role(DB::connection(), 'backup_role').'"';
    }

    /**
     * Concede al ruolo dell'applicazione i permessi indicati, e nient'altro.
     *
     * @param  list<'SELECT'|'INSERT'|'UPDATE'|'DELETE'>  $privileges
     */
    public static function grant(string $table, array $privileges): void
    {
        if (preg_match('/^(app|audit|sites|core)\.[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Tabella non valida: {$table}");
        }
        if ($privileges === [] || array_diff($privileges, self::PRIVILEGES) !== []) {
            throw new InvalidArgumentException('Permessi non validi.');
        }

        DB::statement('GRANT '.implode(', ', $privileges)." ON {$table} TO ".self::appRole());
    }

    /** Ruolo dell'applicazione, già quotato per l'SQL. */
    public static function appRole(): string
    {
        return '"'.self::role(DB::connection(), 'app_role').'"';
    }

    private static function role(ConnectionInterface $connection, string $key): string
    {
        $role = method_exists($connection, 'getConfig') ? $connection->getConfig($key) : null;
        if (! is_string($role) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Ruolo {$key} non configurato sulla connessione del proprietario.");
        }

        return $role;
    }

    private static function assertTable(string $table): void
    {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new InvalidArgumentException("Tabella non valida: {$table}");
        }
    }
}
