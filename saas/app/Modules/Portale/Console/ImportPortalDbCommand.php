<?php

declare(strict_types=1);

namespace App\Modules\Portale\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

/**
 * Importa le tabelle del portale (SQLite) nelle tabelle omonime dello schema `portal` (docs/MIGRAZIONE_DB_PORTALE.md).
 * Ripetibile: ogni riga si inserisce o si aggiorna per chiave primaria (quella della tabella SQLite). Non cancella nulla.
 * Date (stringhe UTC) e 0/1 passano tali e quali. Una tabella che nello schema `portal` non esiste ancora si salta e si
 * segnala (le crea il portale con la sua `migrate()`). `--verify` confronta conteggi e chiavi senza scrivere.
 */
final class ImportPortalDbCommand extends Command
{
    protected $signature = 'saas:portal:import-db {source : copia del file SQLite del portale} {--dry-run : non scrive nulla, mostra i conteggi} {--verify : confronta conteggi e chiavi, senza scrivere}';

    protected $description = 'Importa le tabelle del portale nello schema portal';

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
                foreach ($this->tables($pdo) as $table) {
                    if (! $this->exists($con, $table)) {
                        $this->line(str_pad($table, 20).'manca nello schema portal (saltata)');

                        continue;
                    }
                    $this->line(str_pad($table, 20).$this->importTable($pdo, $con, $table, $dry));
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

    /** @return list<string> */
    private function tables(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $names = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter($names, static fn (mixed $n): bool => is_string($n) && preg_match('/^[a-z][a-z0-9_]*$/', $n) === 1));
    }

    /** @return list<string> */
    private function primaryKey(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query('PRAGMA table_info('.$table.')');
        /** @var list<array<string, mixed>> $info */
        $info = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pk = [];
        foreach ($info as $col) {
            $order = is_numeric($col['pk'] ?? null) ? (int) $col['pk'] : 0;
            if ($order > 0 && is_string($col['name'] ?? null)) {
                $pk[$order] = $col['name'];
            }
        }
        ksort($pk);

        return array_values($pk);
    }

    /** pg_tables e non information_schema: questa nasconde le tabelle create dal ruolo del portale (non sono del proprietario). */
    private function exists(ConnectionInterface $con, string $table): bool
    {
        return $con->selectOne("SELECT 1 AS x FROM pg_tables WHERE schemaname = 'portal' AND tablename = ?", [$table]) !== null;
    }

    private function importTable(PDO $pdo, ConnectionInterface $con, string $table, bool $dry): int
    {
        $pk = $this->primaryKey($pdo, $table);
        $n = 0;
        foreach ($this->readRows($pdo, $table) as $row) {
            $n++;
            if ($dry) {
                continue;
            }
            $values = [];
            foreach ($row as $col => $raw) {
                $values[$col] = is_scalar($raw) ? (string) $raw : null;
            }
            $cols = array_keys($values);
            $update = array_values(array_diff($cols, $pk));
            $conflict = $pk === [] ? '' : ' ON CONFLICT ('.implode(',', $pk).')'.($update === [] ? ' DO NOTHING' : ' DO UPDATE SET '.implode(',', array_map(static fn (string $c): string => $c.' = EXCLUDED.'.$c, $update)));
            $con->statement('INSERT INTO portal.'.$table.' ('.implode(',', $cols).') VALUES ('.implode(',', array_fill(0, count($cols), '?')).')'.$conflict, array_values($values));
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
        foreach ($this->tables($pdo) as $table) {
            if (! $this->exists($con, $table)) {
                $ok = false;
                $this->line(str_pad($table, 20).'MANCA nello schema portal');

                continue;
            }
            $pk = $this->primaryKey($pdo, $table);
            $keyOf = static fn (array $r): string => implode('|', array_map(static fn (string $k): string => is_scalar($r[$k] ?? null) ? (string) $r[$k] : '', $pk));
            $a = array_map($keyOf, $this->readRows($pdo, $table));
            $b = $pk === [] ? [] : array_map(static fn (object $r): string => $keyOf((array) $r), $con->table('portal.'.$table)->get($pk)->all());
            sort($a);
            sort($b);
            $same = $pk === [] ? count($a) === $con->table('portal.'.$table)->count() : $a === $b;
            $ok = $ok && $same;
            $this->line(str_pad($table, 20).str_pad((string) count($a), 6).($same ? 'uguali' : 'DIVERSI'));
        }
        $this->line($ok ? 'Verifica riuscita: conteggi e chiavi coincidono.' : 'Verifica NON riuscita.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
