<?php

declare(strict_types=1);

namespace App\Modules\Core\Platform;

use Illuminate\Database\Connection;

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

    /**
     * Porta i permessi DIRETTI del ruolo sullo schema `portal` a quelli dell'elenco: concede ciò che manca e toglie
     * (solo a questo ruolo) ciò che l'elenco non prevede, anche sulle tabelle fuori elenco di proprietà d'altri. Le tabelle
     * del ruolo stesso non si toccano. Idempotente; non fa nulla se il ruolo o lo schema non esistono. Le tabelle
     * dell'elenco non ancora create sono saltate.
     */
    public static function apply(Connection $db, string $role): void
    {
        $role = self::validRole($role);
        if ($role === '' || $db->selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) === null
            || $db->selectOne('SELECT 1 AS x FROM pg_namespace WHERE nspname = ?', [self::SCHEMA]) === null) {
            return;
        }
        $q = '"'.$role.'"';
        $schema = self::SCHEMA;
        $db->statement("GRANT USAGE ON SCHEMA {$schema} TO {$q}");

        $tables = $db->select(<<<'SQL'
            SELECT c.relname AS t FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = ? AND c.relkind IN ('r', 'p') AND c.relowner <> (SELECT oid FROM pg_roles WHERE rolname = ?)
            SQL, [$schema, $role]);
        foreach ($tables as $row) {
            $name = ((array) $row)['t'] ?? '';
            if (! is_string($name) || $name === '') {
                continue;
            }
            $table = '"'.str_replace('"', '""', $name).'"';
            $wanted = self::TABLES[$name] ?? [];
            $extra = array_diff(self::ALL, $wanted);
            if ($extra !== []) {
                $db->statement('REVOKE '.implode(', ', $extra)." ON TABLE {$schema}.{$table} FROM {$q}");
            }
            if ($wanted !== []) {
                $db->statement('GRANT '.implode(', ', $wanted)." ON TABLE {$schema}.{$table} TO {$q}");
            }
        }

        // Le tabelle future non ricevono più nulla da sole: i permessi si concedono nell'elenco.
        $db->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} REVOKE ALL ON TABLES FROM {$q}");
    }
}
