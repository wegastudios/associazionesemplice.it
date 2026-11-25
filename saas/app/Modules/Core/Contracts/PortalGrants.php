<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Ciò che il ruolo del portale deve avere in modo DIRETTO sullo schema `portal`: una sola fonte, letta dalla migrazione
 * che concede i permessi e dai controlli di `saas:db:revoke-group`. Le altre tabelle dello schema sono del ruolo del
 * portale stesso e non servono concessioni; non ci sono sequenze. CONNECT sul database è già diretto.
 */
final class PortalGrants
{
    public const SCHEMA = 'portal';

    /** Tabella => permessi diretti. */
    public const TABLES = [
        'subscriptions' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'orders' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'saas_orgs' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'releases' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'mail_outbox' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'saas_usage' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'downloads' => ['SELECT', 'INSERT', 'DELETE'],
        'saas_outbox' => ['SELECT', 'INSERT', 'UPDATE'],
        'settings' => ['SELECT', 'INSERT', 'DELETE'],
        'email_templates' => ['SELECT', 'INSERT', 'DELETE'],
        'audit' => ['SELECT', 'INSERT'],
        'events' => ['INSERT', 'DELETE'],
    ];

    /** Tutti i permessi di una tabella: quelli fuori dall'elenco si tolgono al ruolo del portale. */
    private const ALL = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];

    /** Nome del ruolo valido per l'SQL, altrimenti stringa vuota. */
    public static function validRole(mixed $role): string
    {
        return is_string($role) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role) === 1 ? $role : '';
    }

    /** Coppie (tabella, permesso) dell'elenco, come righe di VALUES già letterali. */
    public static function valuesSql(): string
    {
        $rows = [];
        foreach (self::TABLES as $table => $verbs) {
            foreach ($verbs as $verb) {
                $rows[] = "('{$table}', '{$verb}')";
            }
        }

        return implode(', ', $rows);
    }

    /** Il ruolo esiste, lo schema `portal` pure e il ruolo non è l'utente collegato. */
    public static function applicable(Connection $db, string $role): bool
    {
        $role = self::validRole($role);

        return $role !== ''
            && $db->selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ? AND rolname <> current_user', [$role]) !== null
            && $db->selectOne('SELECT 1 AS x FROM pg_namespace WHERE nspname = ?', [self::SCHEMA]) !== null;
    }

    /**
     * Cosa cambierebbe, senza scrivere: righe `[operazione, oggetto, permessi]`. Operazione `concede` o `toglie`; oggetto
     * una tabella dello schema, `(schema)` o `(predefiniti)`. Le tabelle dell'elenco non ancora create e quelle di
     * proprietà del ruolo stesso non compaiono. Nessun nome di ruolo.
     *
     * @return list<array{0: string, 1: string, 2: list<string>}>
     */
    public static function plan(Connection $db, string $role): array
    {
        if (! self::applicable($db, $role)) {
            return [];
        }
        $out = [];
        $schema = self::lit(self::SCHEMA);
        $r = self::lit($role);

        $usage = $db->selectOne("SELECT 1 AS x FROM pg_namespace s, aclexplode(s.nspacl) a WHERE s.nspname = {$schema} AND a.grantee = (SELECT oid FROM pg_roles WHERE rolname = {$r}) AND a.privilege_type = 'USAGE'");
        if ($usage === null) {
            $out[] = ['concede', '(schema)', ['USAGE']];
        }

        $direct = [];
        foreach ($db->select(<<<SQL
            SELECT c.relname AS t, a.privilege_type AS p
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace, pg_roles r, aclexplode(c.relacl) a
            WHERE n.nspname = {$schema} AND c.relkind IN ('r', 'p') AND r.rolname = {$r} AND c.relowner <> r.oid AND a.grantee = r.oid
            SQL) as $row) {
            $row = (array) $row;
            $direct[(string) $row['t']][] = (string) $row['p'];
        }
        $tables = $db->select("SELECT c.relname AS t FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = {$schema} AND c.relkind IN ('r', 'p') AND c.relowner <> (SELECT oid FROM pg_roles WHERE rolname = {$r}) ORDER BY 1");
        foreach ($tables as $row) {
            $name = ((array) $row)['t'] ?? '';
            if (! is_string($name) || $name === '') {
                continue;
            }
            $wanted = self::TABLES[$name] ?? [];
            $have = $direct[$name] ?? [];
            $add = array_values(array_diff($wanted, $have));
            $drop = array_values(array_diff($have, $wanted));
            if ($add !== []) {
                $out[] = ['concede', $name, $add];
            }
            if ($drop !== []) {
                $out[] = ['toglie', $name, $drop];
            }
        }

        $defaults = $db->selectOne("SELECT 1 AS x FROM pg_default_acl d JOIN pg_namespace n ON n.oid = d.defaclnamespace, aclexplode(d.defaclacl) a WHERE n.nspname = {$schema} AND d.defaclobjtype = 'r' AND a.grantee = (SELECT oid FROM pg_roles WHERE rolname = {$r})");
        if ($defaults !== null) {
            $out[] = ['toglie', '(predefiniti)', ['ALL']];
        }

        return $out;
    }

    /**
     * Porta i permessi DIRETTI del ruolo sullo schema `portal` a quelli dell'elenco, in UNA transazione: prima si concede
     * tutto l'elenco, poi si VERIFICA con le ACL dirette che ogni voce sia del ruolo, e solo dopo si toglie ciò che
     * l'elenco non prevede (tabelle fuori elenco di proprietà d'altri e permessi predefiniti). Se la verifica fallisce si
     * annulla tutto (eccezione): il portale non resta mai a metà. Le tabelle del ruolo stesso non si toccano. Idempotente.
     * Restituisce il numero di voci concesse e tolte (dal piano); 0/0 se il ruolo non c'è.
     *
     * @return array{granted: int, revoked: int}
     */
    public static function apply(Connection $db, string $role): array
    {
        if (! self::applicable($db, $role)) {
            return ['granted' => 0, 'revoked' => 0];
        }
        $plan = self::plan($db, $role);
        $q = '"'.$role.'"';
        $schema = self::SCHEMA;
        $r = self::lit($role);

        $db->transaction(function () use ($db, $q, $schema, $r): void {
            $db->statement("GRANT USAGE ON SCHEMA {$schema} TO {$q}");
            $existing = [];
            foreach ($db->select("SELECT c.relname AS t FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = '{$schema}' AND c.relkind IN ('r', 'p') AND c.relowner <> (SELECT oid FROM pg_roles WHERE rolname = {$r})") as $row) {
                $name = ((array) $row)['t'] ?? '';
                if (is_string($name) && $name !== '') {
                    $existing[$name] = '"'.str_replace('"', '""', $name).'"';
                }
            }
            foreach (self::TABLES as $name => $verbs) {
                if (isset($existing[$name])) {
                    $db->statement('GRANT '.implode(', ', $verbs)." ON TABLE {$schema}.{$existing[$name]} TO {$q}");
                }
            }

            // Verifica sulle voci DIRETTE: se manca qualcosa (concessione non riuscita) si annulla tutto.
            $values = self::valuesSql();
            $missing = $db->selectOne(<<<SQL
                SELECT (
                    SELECT count(*) FROM (VALUES {$values}) v(t, p)
                    JOIN pg_class c ON c.oid = to_regclass('{$schema}.' || quote_ident(v.t)), (SELECT oid FROM pg_roles WHERE rolname = {$r}) r
                    WHERE c.relowner <> r.oid
                      AND NOT EXISTS (SELECT 1 FROM aclexplode(c.relacl) a WHERE a.grantee = r.oid AND a.privilege_type = v.p)
                ) + (
                    SELECT count(*) FROM pg_namespace s, (SELECT oid FROM pg_roles WHERE rolname = {$r}) r
                    WHERE s.nspname = '{$schema}' AND NOT EXISTS (SELECT 1 FROM aclexplode(s.nspacl) a WHERE a.grantee = r.oid AND a.privilege_type = 'USAGE')
                ) AS c
                SQL);
            if ((int) (((array) $missing)['c'] ?? 1) > 0) {
                throw new RuntimeException('permessi diretti del portale non concessi: nessuna modifica');
            }

            foreach ($existing as $name => $table) {
                $extra = array_diff(self::ALL, self::TABLES[$name] ?? []);
                if ($extra !== []) {
                    $db->statement('REVOKE '.implode(', ', $extra)." ON TABLE {$schema}.{$table} FROM {$q}");
                }
            }
            $db->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} REVOKE ALL ON TABLES FROM {$q}");
        });

        $granted = 0;
        $revoked = 0;
        foreach ($plan as [$op, , $verbs]) {
            if ($op === 'concede') {
                $granted += count($verbs);
            } else {
                $revoked += count($verbs);
            }
        }

        return ['granted' => $granted, 'revoked' => $revoked];
    }

    private static function lit(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
