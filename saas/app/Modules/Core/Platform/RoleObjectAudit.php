<?php

declare(strict_types=1);

namespace App\Modules\Core\Platform;

use Illuminate\Database\Connection;

/**
 * Controllo per oggetto dei ruoli del database (`saas:health`). Su cPanel i ruoli dell'applicazione, del proprietario e dei
 * backup sono MEMBRI, con ereditarietà, del ruolo di gruppo che possiede il database: non si può evitare, quindi si
 * verifica che quel legame non porti con sé oggetti, permessi o schemi in più. Solo query di sola lettura sul catalogo
 * (pg_roles, pg_auth_members, pg_class, pg_proc, pg_namespace, pg_database, has_*_privilege); nessun nome di ruolo reale
 * entra nei messaggi.
 *
 * Due parti: inspect() interroga il catalogo, evaluate() è pura e decide cosa è un problema e cosa un «limite noto».
 */
final class RoleObjectAudit
{
    /** Schemi attesi (oltre a quelli di sistema, a quelli che iniziano per «org_» e a quelli di SAAS_HEALTH_EXTRA_SCHEMAS). */
    public const EXPECTED_SCHEMAS = ['public', 'app', 'audit', 'sites', 'core', 'portal'];

    private const LIMIT = 5;

    /**
     * Ruoli coinvolti: `start` (applicazione e backup), `chain` (tutte le appartenenze, con ereditarietà e SET ROLE lungo
     * il percorso), `bad` = ruoli di gruppo che app o backup ereditano, diversi dal proprietario; se il proprietario del
     * database è uno di loro vale come gruppo anche pg_database_owner (proprietario implicito di `public`).
     * `holders` = ruoli che non devono possedere nulla: i ruoli di gruppo, l'applicazione e i backup.
     */
    private const ROLES = <<<'SQL'
        WITH RECURSIVE start AS (SELECT oid FROM pg_roles WHERE rolname IN (?, ?)),
        ownr AS (SELECT oid FROM pg_roles WHERE rolname = ?),
        chain(origin, roleid, inh, setr) AS (
            SELECT oid, oid, true, true FROM start
            UNION
            SELECT c.origin, a.roleid,
                   c.inh AND coalesce((to_jsonb(a)->>'inherit_option')::boolean, m.rolinherit),
                   c.setr AND coalesce((to_jsonb(a)->>'set_option')::boolean, true)
            FROM chain c
            JOIN pg_auth_members a ON a.member = c.roleid
            JOIN pg_roles m ON m.oid = c.roleid
        ),
        bad AS (
            SELECT roleid FROM chain WHERE inh AND roleid NOT IN (SELECT oid FROM start) AND roleid NOT IN (SELECT oid FROM ownr)
            UNION
            SELECT r.oid FROM pg_roles r
            WHERE r.rolname = 'pg_database_owner'
              AND EXISTS (SELECT 1 FROM chain c JOIN pg_database d ON d.datname = current_database() AND d.datdba = c.roleid
                          WHERE c.inh AND c.roleid NOT IN (SELECT oid FROM ownr))
        ),
        holders AS (SELECT roleid FROM bad UNION SELECT oid FROM start)
        SQL;

    private const USER_SCHEMA = "n.nspname !~ '^pg_' AND n.nspname <> 'information_schema'";

    /**
     * @return array{unexpectedSchemas: list<string>, owned: list<string>, groupGrants: list<string>, createDatabase: list<string>, createSchema: list<string>, ownerMembers: list<string>, setRole: list<string>, backupWrites?: list<string>}
     */
    public static function inspect(Connection $connection, string $appRole, string $backupRole, string $ownerRole): array
    {
        $roles = [$appRole, $backupRole, $ownerRole];
        $label = static fn (string $role): string => $role === $appRole ? 'applicazione' : 'backup';
        $user = self::USER_SCHEMA;
        $limit = self::LIMIT;
        $expected = '{'.implode(',', self::expectedSchemas()).'}';

        $unexpected = self::column($connection, <<<SQL
            SELECT n.nspname AS v FROM pg_namespace n
            WHERE {$user} AND n.nspname <> ALL (CAST(? AS text[])) AND n.nspname !~ '^org_'
            ORDER BY 1 LIMIT {$limit}
            SQL, [$expected]);

        $owned = self::column($connection, self::ROLES.<<<SQL

            (SELECT 'schema ' || n.nspname AS v FROM pg_namespace n
              WHERE {$user} AND n.nspowner IN (SELECT roleid FROM holders) ORDER BY 1 LIMIT {$limit})
            UNION ALL
            (SELECT CASE c.relkind WHEN 'S' THEN 'sequenza ' WHEN 'v' THEN 'vista ' WHEN 'm' THEN 'vista materializzata ' ELSE 'tabella ' END
                    || n.nspname || '.' || c.relname
               FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE {$user} AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f') AND c.relowner IN (SELECT roleid FROM holders)
              ORDER BY 1 LIMIT {$limit})
            UNION ALL
            (SELECT 'funzione ' || n.nspname || '.' || p.proname
               FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE {$user} AND p.proowner IN (SELECT roleid FROM holders) ORDER BY 1 LIMIT {$limit})
            SQL, $roles);

        $grants = self::column($connection, self::ROLES.<<<SQL

            (SELECT 'schema ' || n.nspname || ' (' || string_agg(DISTINCT a.privilege_type, ', ') || ')' AS v
               FROM pg_namespace n, aclexplode(n.nspacl) a
              WHERE {$user} AND a.grantee IN (SELECT roleid FROM bad) AND a.grantee <> n.nspowner
              GROUP BY n.nspname ORDER BY 1 LIMIT {$limit})
            UNION ALL
            (SELECT 'tabella ' || n.nspname || '.' || c.relname || ' (' || string_agg(DISTINCT a.privilege_type, ', ') || ')'
               FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace, aclexplode(c.relacl) a
              WHERE {$user} AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f') AND a.grantee IN (SELECT roleid FROM bad) AND a.grantee <> c.relowner
              GROUP BY n.nspname, c.relname ORDER BY 1 LIMIT {$limit})
            UNION ALL
            (SELECT 'colonna ' || n.nspname || '.' || c.relname || '.' || att.attname || ' (' || string_agg(DISTINCT a.privilege_type, ', ') || ')'
               FROM pg_attribute att JOIN pg_class c ON c.oid = att.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace, aclexplode(att.attacl) a
              WHERE {$user} AND att.attnum > 0 AND NOT att.attisdropped AND a.grantee IN (SELECT roleid FROM bad) AND a.grantee <> c.relowner
              GROUP BY n.nspname, c.relname, att.attname ORDER BY 1 LIMIT {$limit})
            UNION ALL
            (SELECT 'funzione ' || n.nspname || '.' || p.proname || ' (' || string_agg(DISTINCT a.privilege_type, ', ') || ')'
               FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace, aclexplode(p.proacl) a
              WHERE {$user} AND a.grantee IN (SELECT roleid FROM bad) AND a.grantee <> p.proowner
              GROUP BY n.nspname, p.proname ORDER BY 1 LIMIT {$limit})
            UNION ALL
            (SELECT 'privilegi predefiniti ' || coalesce('dello schema ' || n.nspname, 'di ogni schema') || ' (' || string_agg(DISTINCT a.privilege_type, ', ') || ')'
               FROM pg_default_acl d LEFT JOIN pg_namespace n ON n.oid = d.defaclnamespace, aclexplode(d.defaclacl) a
              WHERE a.grantee IN (SELECT roleid FROM bad) AND a.grantee <> d.defaclrole
              GROUP BY n.nspname ORDER BY 1 LIMIT {$limit})
            SQL, $roles);

        // Il ruolo dei backup legge e basta: nessuna scrittura su nessuna tabella, né sua né ereditata da un gruppo o da PUBLIC.
        $backupWrites = self::column($connection, <<<SQL
            (SELECT n.nspname || '.' || c.relname || ' (' || string_agg(p.priv, ', ' ORDER BY p.priv) || ')' AS v
               FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
               CROSS JOIN (VALUES ('DELETE'), ('INSERT'), ('TRIGGER'), ('TRUNCATE'), ('UPDATE')) p(priv)
              WHERE {$user} AND c.relkind IN ('r', 'p')
                AND (has_table_privilege(CAST(? AS name), c.oid, p.priv)
                     OR (p.priv IN ('INSERT', 'UPDATE') AND has_any_column_privilege(CAST(? AS name), c.oid, p.priv)))
              GROUP BY n.nspname, c.relname ORDER BY 1 LIMIT {$limit})
            UNION ALL
            (SELECT 'sequenza ' || n.nspname || '.' || c.relname || ' (UPDATE)'
               FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE {$user} AND c.relkind = 'S' AND CASE WHEN c.relkind = 'S' THEN has_sequence_privilege(CAST(? AS name), c.oid, 'UPDATE') ELSE false END
              ORDER BY 1 LIMIT {$limit})
            SQL, [$backupRole, $backupRole, $backupRole]);

        $createDatabase = self::column($connection, <<<'SQL'
            SELECT v.label AS v FROM (VALUES ('applicazione', CAST(? AS name)), ('backup', CAST(? AS name))) v(label, role)
            WHERE has_database_privilege(v.role, current_database(), 'CREATE')
            SQL, [$appRole, $backupRole]);

        $createSchema = self::column($connection, <<<SQL
            SELECT v.label || ': ' || n.nspname AS v
            FROM (VALUES ('applicazione', CAST(? AS name)), ('backup', CAST(? AS name))) v(label, role) CROSS JOIN pg_namespace n
            WHERE {$user} AND has_schema_privilege(v.role, n.oid, 'CREATE')
            ORDER BY 1 LIMIT {$limit}
            SQL, [$appRole, $backupRole]);

        $ownerMembers = self::column($connection, self::ROLES.<<<'SQL'

            SELECT DISTINCT o.rolname AS v FROM chain c JOIN pg_roles o ON o.oid = c.origin
            WHERE c.roleid IN (SELECT oid FROM ownr) AND c.roleid <> c.origin
            SQL, $roles);

        $setRole = self::column($connection, self::ROLES.<<<'SQL'

            SELECT DISTINCT o.rolname AS v FROM chain c JOIN pg_roles o ON o.oid = c.origin
            JOIN pg_database d ON d.datname = current_database() AND d.datdba = c.roleid
            WHERE c.setr AND c.roleid IN (SELECT roleid FROM bad)
            SQL, $roles);

        return [
            'unexpectedSchemas' => $unexpected,
            'owned' => $owned,
            'groupGrants' => $grants,
            'backupWrites' => $backupWrites,
            'createDatabase' => $createDatabase,
            'createSchema' => $createSchema,
            'ownerMembers' => array_map($label, $ownerMembers),
            'setRole' => array_map($label, $setRole),
        ];
    }

    /**
     * Cosa è un problema e cosa un «limite noto». Il limite (SET ROLE verso il ruolo che possiede il database) si
     * segnala solo se i punti precedenti sono tutti in ordine: allora il controllo per oggetto regge e il limite resta
     * un'informazione.
     *
     * @param  array{unexpectedSchemas: list<string>, owned: list<string>, groupGrants: list<string>, createDatabase: list<string>, createSchema: list<string>, ownerMembers: list<string>, setRole: list<string>, backupWrites?: list<string>}  $facts
     * @return array{problems: list<string>, critical: list<string>, notes: list<string>}
     */
    public static function evaluate(array $facts): array
    {
        $problems = [];
        if ($facts['unexpectedSchemas'] !== []) {
            $problems[] = 'schemi inattesi ('.implode(', ', $facts['unexpectedSchemas']).'): togliere quelli non voluti con il ruolo proprietario (DROP SCHEMA) o, se servono, elencarli in SAAS_HEALTH_EXTRA_SCHEMAS';
        }
        if ($facts['owned'] !== []) {
            $problems[] = 'oggetti di proprietà dell\'applicazione, dei backup o di un ruolo di gruppo, non del proprietario ('.implode(', ', $facts['owned']).'): passarli al ruolo proprietario (ALTER ... OWNER TO) e crearli solo con le migrazioni';
        }
        if ($facts['groupGrants'] !== []) {
            $problems[] = 'un ruolo di gruppo ha permessi oltre quelli attesi ('.implode(', ', $facts['groupGrants']).'): revocarli (REVOKE ... FROM il ruolo di gruppo; per i privilegi predefiniti ALTER DEFAULT PRIVILEGES ... REVOKE): cPanel li può rimettere da solo, per questo si ricontrolla a ogni rilascio';
        }
        if (($facts['backupWrites'] ?? []) !== []) {
            $problems[] = 'il ruolo dei backup può scrivere, direttamente o per eredità ('.implode(', ', $facts['backupWrites']).'): deve essere di sola lettura, revocare INSERT/UPDATE/DELETE/TRUNCATE/TRIGGER (REVOKE ... FROM il ruolo o dal ruolo di gruppo da cui li eredita)';
        }
        if ($facts['createDatabase'] !== []) {
            $problems[] = 'CREATE sul database ('.implode(', ', $facts['createDatabase']).'): revocarlo al ruolo, o al ruolo di gruppo da cui lo eredita (REVOKE CREATE ON DATABASE)';
        }
        if ($facts['createSchema'] !== []) {
            $problems[] = 'CREATE su schemi non previsto ('.implode(', ', $facts['createSchema']).'): revocarlo al ruolo, o al ruolo di gruppo da cui lo eredita (REVOKE CREATE ON SCHEMA)';
        }
        // Sempre un errore, anche senza SAAS_HEALTH_STRICT: se l'applicazione o i backup sono membri del ruolo proprietario
        // la separazione dei ruoli non esiste.
        $critical = [];
        if ($facts['ownerMembers'] !== []) {
            $critical[] = 'membro del ruolo proprietario ('.implode(', ', $facts['ownerMembers']).'): la separazione dei ruoli non esiste, toglierlo (REVOKE del ruolo proprietario dal ruolo)';
        }

        $notes = [];
        if ($problems === [] && $critical === [] && $facts['setRole'] !== []) {
            $notes[] = 'limite noto: '.implode(' e ', $facts['setRole']).' possono assumere con SET ROLE il ruolo di gruppo che possiede il database (su cPanel non si evita): per questo si controlla oggetto per oggetto, e qui è tutto in ordine';
        }

        return ['problems' => $problems, 'critical' => $critical, 'notes' => $notes];
    }

    /** @return list<string> */
    private static function expectedSchemas(): array
    {
        $extra = config('saas.health_extra_schemas');
        $extra = is_array($extra) ? array_values(array_filter($extra, static fn (mixed $s): bool => is_string($s) && preg_match('/^[a-z][a-z0-9_]*$/', $s) === 1)) : [];

        return [...self::EXPECTED_SCHEMAS, ...$extra];
    }

    /**
     * @param  list<string>  $bindings
     * @return list<string>
     */
    private static function column(Connection $connection, string $sql, array $bindings): array
    {
        $out = [];
        foreach ($connection->select($sql, $bindings) as $row) {
            if (is_object($row) && is_string($row->v ?? null)) {
                $out[] = $row->v;
            }
        }

        return $out;
    }
}
