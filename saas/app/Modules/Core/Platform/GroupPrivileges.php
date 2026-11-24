<?php

declare(strict_types=1);

namespace App\Modules\Core\Platform;

use Illuminate\Database\Connection;
use Throwable;

/**
 * Toglie al ruolo di gruppo che possiede il database i permessi che cPanel (`update_privileges ... ALL`) gli rimette su
 * tutte le tabelle: app e backup, membri del gruppo con ereditarietà, li erediterebbero (scrittura, TRUNCATE, CREATE).
 * Si usa la connessione del proprietario. Non tocca mai i permessi diretti di app, backup, proprietario e portale, né la
 * proprietà degli oggetti, né CONNECT. Idempotente: si agisce solo dove il gruppo ha una voce nei permessi.
 *
 * Nessun nome di ruolo o di oggetto esce da qui: solo conteggi (i log dei workflow sono pubblici).
 */
final class GroupPrivileges
{
    /** Voci dei permessi predefiniti che si revocano: tabelle, sequenze, funzioni, tipi. */
    private const DEFAULT_KINDS = ['r' => 'TABLES', 'S' => 'SEQUENCES', 'f' => 'FUNCTIONS', 'T' => 'TYPES'];

    /** Il ruolo di gruppo: proprietario del database, senza accesso, diverso dall'utente collegato. Null se non c'è. */
    public static function groupRole(Connection $db): ?string
    {
        $row = $db->selectOne(<<<'SQL'
            SELECT r.rolname AS g FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba
            WHERE d.datname = current_database() AND NOT r.rolcanlogin
              AND r.rolname <> 'pg_database_owner' AND r.rolname <> current_user
            SQL);

        return is_object($row) && is_string($row->g ?? null) ? $row->g : null;
    }

    /**
     * Cosa ha ancora il gruppo.
     *
     * @return array{tables: array<string, int>, sequences: array<string, int>, functions: array<string, int>, columns: list<array{0: string, 1: string, 2: string}>, defaults: list<array{0: string, 1: ?string, 2: string, 3: bool}>, database: list<string>}
     */
    public static function inventory(Connection $db, string $group): array
    {
        $list = self::pgArray(self::schemas($db));
        $bySchema = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row->s] = (int) $row->c;
            }

            return $out;
        };

        $tables = $db->select(<<<'SQL'
            SELECT n.nspname AS s, count(*) AS c FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = ANY (CAST(? AS text[])) AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
              AND EXISTS (SELECT 1 FROM aclexplode(c.relacl) a WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = ?) AND a.grantee <> c.relowner)
            GROUP BY n.nspname
            SQL, [$list, $group]);
        $sequences = $db->select(<<<'SQL'
            SELECT n.nspname AS s, count(*) AS c FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = ANY (CAST(? AS text[])) AND c.relkind = 'S'
              AND EXISTS (SELECT 1 FROM aclexplode(c.relacl) a WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = ?) AND a.grantee <> c.relowner)
            GROUP BY n.nspname
            SQL, [$list, $group]);
        $functions = $db->select(<<<'SQL'
            SELECT n.nspname AS s, count(*) AS c FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = ANY (CAST(? AS text[]))
              AND EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = ?) AND a.grantee <> p.proowner)
            GROUP BY n.nspname
            SQL, [$list, $group]);

        $columns = [];
        foreach ($db->select(<<<'SQL'
            SELECT n.nspname AS s, c.relname AS t, att.attname AS col
            FROM pg_attribute att JOIN pg_class c ON c.oid = att.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = ANY (CAST(? AS text[])) AND att.attnum > 0 AND NOT att.attisdropped
              AND EXISTS (SELECT 1 FROM aclexplode(att.attacl) a WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = ?) AND a.grantee <> c.relowner)
            SQL, [$list, $group]) as $row) {
            $columns[] = [(string) $row->s, (string) $row->t, (string) $row->col];
        }

        // Permessi predefiniti: [ruolo che crea gli oggetti, schema (null = di ogni schema), tipo, ci si può agire].
        $defaults = [];
        foreach ($db->select(<<<'SQL'
            SELECT pg_get_userbyid(d.defaclrole) AS r, n.nspname AS s, d.defaclobjtype AS k, pg_has_role(current_user, d.defaclrole, 'MEMBER') AS m
            FROM pg_default_acl d LEFT JOIN pg_namespace n ON n.oid = d.defaclnamespace
            WHERE d.defaclobjtype IN ('r', 'S', 'f', 'T') AND (n.nspname IS NULL OR n.nspname = ANY (CAST(? AS text[])))
              AND EXISTS (SELECT 1 FROM aclexplode(d.defaclacl) a WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = ?) AND a.grantee <> d.defaclrole)
            SQL, [$list, $group]) as $row) {
            $defaults[] = [(string) $row->r, $row->s === null ? null : (string) $row->s, (string) $row->k, (bool) $row->m];
        }

        // CREATE e TEMPORARY sul database (CONNECT non si tocca mai). Senza ACL esplicita il proprietario li ha tutti.
        $database = [];
        foreach (['CREATE', 'TEMPORARY'] as $privilege) {
            $has = $db->selectOne(<<<'SQL'
                SELECT (d.datacl IS NULL OR EXISTS (SELECT 1 FROM aclexplode(d.datacl) a WHERE a.grantee = d.datdba AND a.privilege_type = ?)) AS h
                FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba
                WHERE d.datname = current_database() AND r.rolname = ?
                SQL, [$privilege, $group]);
            if (is_object($has) && $has->h) {
                $database[] = $privilege;
            }
        }

        return [
            'tables' => $bySchema($tables),
            'sequences' => $bySchema($sequences),
            'functions' => $bySchema($functions),
            'columns' => $columns,
            'defaults' => $defaults,
            'database' => $database,
        ];
    }

    /**
     * Conteggi per tipo.
     *
     * @param  array{tables: array<string, int>, sequences: array<string, int>, functions: array<string, int>, columns: list<array{0: string, 1: string, 2: string}>, defaults: list<array{0: string, 1: ?string, 2: string, 3: bool}>, database: list<string>}  $inventory
     * @return array{tabelle: int, sequenze: int, funzioni: int, colonne: int, predefiniti: int, database: int}
     */
    public static function counts(array $inventory): array
    {
        return [
            'tabelle' => array_sum($inventory['tables']),
            'sequenze' => array_sum($inventory['sequences']),
            'funzioni' => array_sum($inventory['functions']),
            'colonne' => count($inventory['columns']),
            'predefiniti' => count($inventory['defaults']),
            'database' => count($inventory['database']),
        ];
    }

    /**
     * Toglie i permessi, in una transazione. Restituisce quanti ne ha tolti e quanti restano (voci che il proprietario
     * non può togliere: oggetti o ruoli che non gli appartengono, CREATE sul database senza SET ROLE verso il gruppo).
     *
     * @return array{removed: int, remaining: int}
     */
    public static function revoke(Connection $db, string $group): array
    {
        $g = self::quote($group);
        $before = self::inventory($db, $group);
        $total = array_sum(self::counts($before));

        $db->transaction(function () use ($db, $group, $g, $before): void {
            foreach (['tables' => 'TABLES', 'sequences' => 'SEQUENCES', 'functions' => 'FUNCTIONS'] as $key => $kind) {
                foreach (array_keys($before[$key]) as $schema) {
                    $db->statement("REVOKE ALL ON ALL {$kind} IN SCHEMA ".self::quote($schema)." FROM {$g}");
                }
            }

            // Le colonne con un permesso proprio che la tabella non aveva restano: si rilegge e si tolgono una per una.
            $perTable = [];
            foreach (self::inventory($db, $group)['columns'] as [$schema, $table, $column]) {
                $perTable[self::quote($schema).'.'.self::quote($table)][] = self::quote($column);
            }
            foreach ($perTable as $table => $columns) {
                $db->statement('REVOKE ALL ('.implode(', ', $columns).") ON TABLE {$table} FROM {$g}");
            }

            foreach ($before['defaults'] as [$role, $schema, $kind, $member]) {
                if (! $member) {
                    continue;
                }
                $in = $schema === null ? '' : ' IN SCHEMA '.self::quote($schema);
                $db->statement('ALTER DEFAULT PRIVILEGES FOR ROLE '.self::quote($role)."{$in} REVOKE ALL ON ".self::DEFAULT_KINDS[$kind]." FROM {$g}");
            }

            if ($before['database'] !== []) {
                try {
                    $db->transaction(function () use ($db, $g, $before): void {
                        $db->statement("SET LOCAL ROLE {$g}");
                        $db->statement('REVOKE '.implode(', ', $before['database']).' ON DATABASE '.self::quote($db->getDatabaseName())." FROM {$g}");
                        $db->statement('RESET ROLE');
                    });
                } catch (Throwable) {
                    // Il proprietario non può assumere il ruolo di gruppo: resta tra i permessi rimasti.
                }
            }
        });

        $remaining = array_sum(self::counts(self::inventory($db, $group)));

        return ['removed' => max(0, $total - $remaining), 'remaining' => $remaining];
    }

    /** @return list<string> */
    private static function schemas(Connection $db): array
    {
        $names = [...RoleObjectAudit::EXPECTED_SCHEMAS];
        foreach ($db->select("SELECT nspname FROM pg_namespace WHERE nspname ~ '^org_'") as $row) {
            $names[] = (string) $row->nspname;
        }

        return $names;
    }

    /** @param  list<string>  $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $v): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $values)).'}';
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
