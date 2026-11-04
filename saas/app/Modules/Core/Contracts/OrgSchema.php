<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

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
        if (preg_match('/^[a-z_]+$/', $orgColumn) !== 1) {
            throw new InvalidArgumentException('Colonna non valida.');
        }

        $condition = "{$orgColumn} = NULLIF(current_setting('app.org_id', true), '')::uuid";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY org_isolation ON {$table} USING ({$condition}) WITH CHECK ({$condition})");
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
        $role = DB::connection()->getConfig('app_role');
        if (! is_string($role) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException('Ruolo dell\'applicazione non configurato sulla connessione delle migrazioni.');
        }

        return '"'.$role.'"';
    }

    private static function assertTable(string $table): void
    {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new InvalidArgumentException("Tabella non valida: {$table}");
        }
    }
}
