<?php

declare(strict_types=1);

namespace App\Modules\Backup;

use App\Modules\Backup\Crypto\BackupCipher;
use App\Modules\Core\Contracts\DatabaseNames;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dump orari cifrati di ogni database (condiviso e dedicati), su uno spazio esterno in UE, e prova di ripristino.
 *
 * - pg_dump gira con il ruolo dei backup (sola lettura, ammesso dalle policy) e con --enable-row-security;
 * - il dump si cifra sul server con la chiave pubblica dei backup prima di uscire;
 * - accanto al dump un piccolo file JSON con impronta e numero di righe per tabella (nessun dato), per la prova.
 */
final readonly class Backups
{
    public function __construct(
        private DatabaseNames $databases,
        private DatabaseManager $db,
        private BackupConfig $config,
        private Filesystem $disk,
    ) {}

    /** @return array{path: string, size: int, sha256: string, tables: array<string, int>} */
    public function dump(string $name, CarbonImmutable $at): array
    {
        $connection = $this->databases->backupConnection($name);
        $settings = $this->settings($connection);
        $tables = $this->rowCounts($this->db->connection($connection), false);
        $plain = $this->temp();
        $encrypted = $this->temp();

        try {
            $this->run([
                $this->config->binary('pg_dump'), '--format=custom', '--compress=6', '--no-password', '--enable-row-security',
                '--host='.$settings['host'], '--port='.$settings['port'], '--username='.$settings['username'],
                '--dbname='.$settings['database'], '--file='.$plain,
            ], $settings['password']);

            $in = fopen($plain, 'rb') ?: throw new RuntimeException('Dump non leggibile.');
            $out = fopen($encrypted, 'wb') ?: throw new RuntimeException('File cifrato non scrivibile.');
            BackupCipher::encrypt($in, $out, $this->config->publicKey());
            fclose($in);
            fclose($out);

            $path = sprintf('%s/%s/%s/%s-%s.dump.enc', $this->config->prefix(), $name, $at->utc()->format('Y/m'), $name, $at->utc()->format('Ymd\THis\Z'));
            $sha256 = (string) hash_file('sha256', $encrypted);
            $size = (int) filesize($encrypted);

            $stream = fopen($encrypted, 'rb') ?: throw new RuntimeException('File cifrato non leggibile.');
            $this->disk->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $this->disk->put($path.'.json', (string) json_encode([
                'database' => $name,
                'created_at' => $at->utc()->toIso8601String(),
                'size' => $size,
                'sha256' => $sha256,
                'tables' => $tables,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return ['path' => $path, 'size' => $size, 'sha256' => $sha256, 'tables' => $tables];
        } finally {
            @unlink($plain);
            @unlink($encrypted);
        }
    }

    public function latest(string $name): ?string
    {
        $files = $this->dumps($name);

        return $files === [] ? null : array_key_last($files);
    }

    /**
     * Scarica un dump, lo decifra, lo ripristina nel database di prova (che viene svuotato) e confronta il numero di
     * righe di ogni tabella con quello registrato al momento del dump.
     *
     * @return array{path: string, tables: int, rows: int, seconds: float}
     */
    public function restoreTest(string $name, ?string $path = null): array
    {
        $started = microtime(true);
        $path ??= $this->latest($name) ?? throw new RuntimeException("Nessun backup di {$name}.");
        $manifest = json_decode((string) $this->disk->get($path.'.json'), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ! is_array($manifest['tables'] ?? null) || ! is_string($manifest['sha256'] ?? null)) {
            throw new RuntimeException('Descrizione del backup mancante.');
        }

        $encrypted = $this->temp();
        $plain = $this->temp();
        try {
            $in = $this->disk->readStream($path) ?? throw new RuntimeException('Backup non leggibile.');
            $out = fopen($encrypted, 'wb') ?: throw new RuntimeException('File temporaneo non scrivibile.');
            stream_copy_to_stream($in, $out);
            fclose($out);
            if (! hash_equals($manifest['sha256'], (string) hash_file('sha256', $encrypted))) {
                throw new RuntimeException('Impronta del backup diversa da quella registrata.');
            }

            $in = fopen($encrypted, 'rb') ?: throw new RuntimeException('Backup non leggibile.');
            $out = fopen($plain, 'wb') ?: throw new RuntimeException('File temporaneo non scrivibile.');
            BackupCipher::decrypt($in, $out, $this->config->keyPair());
            fclose($in);
            fclose($out);

            $target = $this->db->connection('pgsql_restore');
            foreach (['sites', 'audit', 'app', 'core', 'portal'] as $schema) {
                $target->statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
            }
            $target->statement('DROP TABLE IF EXISTS public.migrations');

            $settings = $this->settings('pgsql_restore');
            $this->run([
                $this->config->binary('pg_restore'), '--no-owner', '--no-privileges', '--exit-on-error', '--no-password',
                '--host='.$settings['host'], '--port='.$settings['port'], '--username='.$settings['username'],
                '--dbname='.$settings['database'], $plain,
            ], $settings['password']);

            $restored = $this->rowCounts($target, true);
            /** @var array<string, int> $expected */
            $expected = $manifest['tables'];
            ksort($expected);
            ksort($restored);
            if ($restored !== $expected) {
                $diff = array_keys(array_diff_assoc($expected, $restored) + array_diff_assoc($restored, $expected));
                throw new RuntimeException('Righe ripristinate diverse dal dump: '.implode(', ', array_slice($diff, 0, 10)));
            }

            return ['path' => $path, 'tables' => count($restored), 'rows' => array_sum($restored), 'seconds' => round(microtime(true) - $started, 1)];
        } finally {
            @unlink($plain);
            @unlink($encrypted);
        }
    }

    /**
     * Tiene tutti i dump delle ultime 48 ore, poi il primo di ogni giorno per 30 giorni; cancella il resto.
     *
     * @return int dump cancellati
     */
    public function prune(string $name, CarbonImmutable $now): int
    {
        $hourly = $now->subHours(48);
        $daily = $now->subDays(30);
        $kept = [];
        $deleted = 0;

        foreach ($this->dumps($name) as $path => $at) {
            $day = $at->format('Y-m-d');
            $keep = $at->gte($hourly) || ($at->gte($daily) && ! isset($kept[$day]));
            if ($keep) {
                $kept[$day] = true;

                continue;
            }
            $this->disk->delete([$path, $path.'.json']);
            $deleted++;
        }

        return $deleted;
    }

    /** @return array<string, CarbonImmutable> percorso => ora del dump, dal più vecchio */
    private function dumps(string $name): array
    {
        $found = [];
        foreach ($this->disk->allFiles($this->config->prefix().'/'.$name) as $file) {
            if (preg_match('/-(\d{8}T\d{6}Z)\.dump\.enc$/', $file, $m) === 1) {
                $found[$file] = CarbonImmutable::createFromFormat('Ymd\THis\Z', $m[1], 'UTC') ?: CarbonImmutable::now();
            }
        }
        asort($found);

        return $found;
    }

    /**
     * Righe per tabella (solo tabelle vere: le partizioni sì, la tabella madre no).
     *
     * @return array<string, int>
     */
    private function rowCounts(ConnectionInterface $connection, bool $restored): array
    {
        $tables = $connection->select(<<<'SQL'
            SELECT n.nspname || '.' || c.relname AS t
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind = 'r' AND (n.nspname IN ('core', 'app', 'audit', 'sites', 'portal') OR (n.nspname = 'public' AND c.relname = 'migrations'))
            ORDER BY 1
        SQL);

        $counts = [];
        foreach ($tables as $row) {
            $table = is_object($row) && isset($row->t) && is_string($row->t) ? $row->t : null;
            if ($table === null || preg_match('/^[a-z_]+\.[a-z0-9_]+$/', $table) !== 1) {
                continue;
            }
            if ($restored) {
                // Solo nel database di prova: il proprietario conta tutte le righe senza la RLS.
                $connection->statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            }
            $count = $connection->scalar("SELECT count(*) FROM {$table}");
            $counts[$table] = is_numeric($count) ? (int) $count : 0;
        }

        return $counts;
    }

    /** @return array{host: string, port: string, database: string, username: string, password: string} */
    private function settings(string $connection): array
    {
        $c = $this->db->connection($connection)->getConfig();
        $get = static fn (string $key): string => is_array($c) && is_scalar($c[$key] ?? null) ? (string) $c[$key] : '';

        return ['host' => $get('host'), 'port' => $get('port'), 'database' => $get('database'), 'username' => $get('username'), 'password' => $get('password')];
    }

    /** @param  list<string>  $command */
    private function run(array $command, string $password): void
    {
        $process = new Process($command, null, ['PGPASSWORD' => $password, 'PGCONNECT_TIMEOUT' => '10'], null, 3600);
        $process->run();
        if (! $process->isSuccessful()) {
            // Solo l'inizio dell'errore e mai la riga di comando: niente credenziali nei log.
            throw new RuntimeException(basename($command[0]).' non riuscito: '.mb_substr(trim($process->getErrorOutput()), 0, 300));
        }
    }

    /**
     * File temporaneo in una cartella privata del SaaS (0700), non in /tmp: su un hosting condiviso /tmp può essere
     * visibile ad altri account, e lì passa il dump in chiaro.
     */
    private function temp(): string
    {
        $dir = storage_path('app/tmp');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cartella temporanea non disponibile.');
        }
        $file = $dir.'/'.bin2hex(random_bytes(16));
        $handle = fopen($file, 'xb') ?: throw new RuntimeException('File temporaneo non disponibile.');
        fclose($handle);
        chmod($file, 0600);

        return $file;
    }
}
