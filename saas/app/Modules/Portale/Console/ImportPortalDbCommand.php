<?php

declare(strict_types=1);

namespace App\Modules\Portale\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

/**
 * Importa i dati durevoli del portale (SQLite) nello schema `portal` (docs/MIGRAZIONE_DB_PORTALE.md). Ripetibile: ogni riga
 * si inserisce o si aggiorna per chiave. Non cancella nulla. Le date del portale sono UTC e restano tali.
 * `--verify` confronta conteggi e chiavi senza scrivere.
 */
final class ImportPortalDbCommand extends Command
{
    /**
     * Per ogni tabella: chiave primaria, colonne booleane e colonne data (testo UTC → timestamptz).
     *
     * @var array<string, array{pk: list<string>, bool: list<string>, ts: list<string>, from?: string}>
     */
    private const TABLES = [
        'subscriptions' => ['pk' => ['id'], 'bool' => ['cancel_at_period_end'], 'ts' => ['expires_at', 'created_at', 'updated_at', 'reminded_for', 'trial_used_at', 'last_seen_at', 'domain_changed_at']],
        'orders' => ['pk' => ['id'], 'bool' => ['cancel_at_period_end'], 'ts' => ['created_at', 'updated_at', 'paid_at']],
        'orgs' => ['pk' => ['ref'], 'bool' => [], 'ts' => ['created_at'], 'from' => 'saas_orgs'],
        'outbox' => ['pk' => ['id'], 'bool' => [], 'ts' => ['next_at', 'done_at', 'created_at'], 'from' => 'saas_outbox'],
        'releases' => ['pk' => ['product', 'version'], 'bool' => [], 'ts' => ['created_at']],
        'downloads' => ['pk' => ['id'], 'bool' => [], 'ts' => ['at']],
        'settings' => ['pk' => ['name'], 'bool' => [], 'ts' => []],
        'email_templates' => ['pk' => ['event'], 'bool' => ['enabled'], 'ts' => ['updated_at']],
        'audit' => ['pk' => ['id'], 'bool' => [], 'ts' => ['at']],
        'events' => ['pk' => ['provider', 'event_id'], 'bool' => [], 'ts' => ['processed_at']],
    ];

    protected $signature = 'saas:portal:import-db {source : copia del file SQLite del portale} {--dry-run : non scrive nulla, mostra i conteggi} {--verify : confronta conteggi e chiavi, senza scrivere}';

    protected $description = 'Importa i dati durevoli del portale nello schema portal';

    public function handle(DatabaseManager $db): int
    {
        $source = (string) $this->argument('source');
        if (! is_file($source) || ! extension_loaded('pdo_sqlite')) {
            $this->error('File non trovato o estensione pdo_sqlite mancante.');

            return self::FAILURE;
        }

        $con = $db->connection('pgsql_owner');

        try {
            $pdo = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($this->option('verify') === true) {
                return $this->verify($pdo, $con);
            }
            $dry = $this->option('dry-run') === true;
            $run = function () use ($pdo, $con, $dry): void {
                foreach (self::TABLES as $table => $def) {
                    $n = $this->importTable($pdo, $con, $table, $def, $dry);
                    $this->line(str_pad($table, 18).$n);
                }
            };
            $dry ? $run() : $con->transaction($run);
        } catch (Throwable $e) {
            $this->error('Importazione non riuscita (nulla è stato scritto): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('dry-run') === true ? 'Prova a vuoto: nulla è stato scritto.' : 'Importazione finita. Controlla con --verify.');

        return self::SUCCESS;
    }

    /** @param  array{pk: list<string>, bool: list<string>, ts: list<string>, from?: string}  $def */
    private function importTable(PDO $pdo, ConnectionInterface $con, string $table, array $def, bool $dry): int
    {
        $rows = $this->readRows($pdo, $def['from'] ?? $table);
        $n = 0;
        foreach ($rows as $row) {
            $n++;
            if ($dry) {
                continue;
            }
            $values = [];
            foreach ($row as $col => $raw) {
                $v = is_scalar($raw) ? (string) $raw : null;
                $values[$col] = match (true) {
                    $v === null => null,
                    in_array($col, $def['bool'], true) => $v === '1',
                    in_array($col, $def['ts'], true) => ($v === '' ? null : $v.'+00'),
                    default => $v,
                };
            }
            $cols = array_keys($values);
            $update = array_values(array_diff($cols, $def['pk']));
            $sql = 'INSERT INTO portal.'.$table.' ('.implode(',', $cols).') VALUES ('.implode(',', array_fill(0, count($cols), '?')).') ON CONFLICT ('.implode(',', $def['pk']).')'
                .($update === [] ? ' DO NOTHING' : ' DO UPDATE SET '.implode(',', array_map(static fn (string $c): string => $c.' = EXCLUDED.'.$c, $update)));
            $con->statement($sql, array_values($values));
        }

        return $n;
    }

    /** @return list<array<string, mixed>> */
    private function readRows(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query('SELECT * FROM '.$table);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function verify(PDO $pdo, ConnectionInterface $con): int
    {
        $ok = true;
        foreach (self::TABLES as $table => $def) {
            $src = $this->readRows($pdo, $def['from'] ?? $table);
            $a = array_map(static fn (array $r): string => implode('|', array_map(static fn (string $k): string => self::key($r[$k] ?? null), $def['pk'])), $src);
            $b = array_map(static function (object $r) use ($def): string {
                $r = (array) $r;

                return implode('|', array_map(static fn (string $k): string => self::key($r[$k] ?? null), $def['pk']));
            }, $con->table('portal.'.$table)->get($def['pk'])->all());
            sort($a);
            sort($b);
            $same = $a === $b;
            $ok = $ok && $same;
            $this->line(str_pad($table, 18).str_pad((string) count($a), 6).str_pad((string) count($b), 6).($same ? 'uguali' : 'DIVERSI'));
        }
        $this->line($ok ? 'Verifica riuscita: conteggi e chiavi coincidono.' : 'Verifica NON riuscita.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private static function key(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
