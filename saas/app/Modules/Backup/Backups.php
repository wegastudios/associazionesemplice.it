<?php

declare(strict_types=1);

namespace App\Modules\Backup;

use App\Modules\Backup\Contracts\BackupCompression;
use App\Modules\Backup\Crypto\BackupCipher;
use App\Modules\Backup\Crypto\BackupEncryptor;
use App\Modules\Backup\Crypto\EncryptedPipe;
use App\Modules\Backup\Crypto\EncryptedPipeStream;
use App\Modules\Core\Contracts\DatabaseNames;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dump orari cifrati di ogni database (condiviso e dedicati), su uno spazio esterno in UE, e prova di ripristino.
 *
 * - pg_dump gira con il ruolo dei backup (sola lettura, ammesso dalle policy) e con --enable-row-security;
 * - il dump si cifra sul server con la chiave pubblica dei backup prima di uscire;
 * - accanto al dump un piccolo file JSON con impronta e numero di righe per tabella (nessun dato), per la prova.
 */
final readonly class Backups implements BackupCompression
{
    public function __construct(
        private DatabaseNames $databases,
        private DatabaseManager $db,
        private BackupConfig $config,
        private Filesystem $disk,
    ) {}

    public function fellBackToGzip(): bool
    {
        return str_starts_with($this->config->compression(), 'zstd') && ! $this->zstdAvailable();
    }

    /** Compressione effettiva: quella configurata, ma gzip da solo se il pg_dump del server non sa fare zstd. */
    public function compression(): string
    {
        return $this->fellBackToGzip() ? 'gzip:6' : $this->config->compression();
    }

    /**
     * Prova a vuoto, una volta al giorno (in cache): pg_dump rifiuta zstd mentre legge le opzioni, prima di collegarsi.
     * Qualunque altro esito (anche un errore di collegamento, o un programma che non parte) vale «c'è».
     */
    private function zstdAvailable(): bool
    {
        $binary = $this->config->binary('pg_dump');
        $known = Cache::remember('backup:zstd:'.md5($binary), 86400, function () use ($binary): string {
            $probe = new Process([$binary, '--compress=zstd:1', '--file='.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), '--no-password', '--host=127.0.0.1', '--port=1', '--dbname=prova'], null, ['PGCONNECT_TIMEOUT' => '2'], null, 15);
            try {
                $probe->run();
            } catch (Throwable) {
                return 'yes';
            }

            return preg_match('/does not support compression|invalid compression|unrecognized compression|unsupported compression/i', $probe->getErrorOutput()) === 1 ? 'no' : 'yes';
        });

        return $known !== 'no';
    }

    /**
     * Un solo flusso: pg_dump → cifratura → spazio dei backup, senza file in chiaro né cifrati sul disco del server.
     * Le righe per tabella sono stimate dalle statistiche (quelle sotto la soglia si contano davvero); «estimated»
     * elenca le tabelle stimate. L'impronta è calcolata mentre il file parte. Se pg_dump fallisce il file parziale si cancella.
     *
     * @return array{path: string, size: int, sha256: string, tables: array<string, int>, estimated: list<string>}
     */
    public function dump(string $name, CarbonImmutable $at): array
    {
        $connection = $this->databases->backupConnection($name);
        $settings = $this->settings($connection);
        ['tables' => $tables, 'estimated' => $estimated] = $this->statCounts($this->db->connection($connection));

        $process = new Process([
            ...$this->lowPriority(),
            $this->config->binary('pg_dump'), '--format=custom', '--compress='.$this->compression(), '--no-password', '--enable-row-security',
            '--host='.$settings['host'], '--port='.$settings['port'], '--username='.$settings['username'],
            '--dbname='.$settings['database'],
        ], null, ['PGPASSWORD' => $settings['password'], 'PGCONNECT_TIMEOUT' => '10'], null, 3600);

        // Database, ora e ambiente entrano nel dato autenticato: un dump non si può spacciare per un altro.
        $pipe = new EncryptedPipe($this->dumpOutput($process), new BackupEncryptor($this->config->publicKey(), [
            'created_at' => $at->utc()->format('Y-m-d\TH:i:s\Z'),
            'database' => $name,
            'env' => $this->config->prefix(),
        ]));
        $path = sprintf('%s/%s/%s/%s-%s.dump.enc', $this->config->prefix(), $name, $at->utc()->format('Y/m'), $name, $at->utc()->format('Ymd\THis\Z'));

        $process->start();
        $stream = EncryptedPipeStream::open($pipe);
        try {
            $this->disk->writeStream($path, $stream);
        } catch (Throwable $e) {
            $process->stop(5);
            $this->discard($path);

            throw $e;
        } finally {
            fclose($stream);
        }

        if ($pipe->error() instanceof Throwable || ! $pipe->completed() || ! $process->isSuccessful()) {
            $process->stop(5);
            $this->discard($path);
            // Solo l'inizio dell'errore e mai la riga di comando: niente credenziali nei log.
            throw new RuntimeException('pg_dump non riuscito: '.mb_substr(trim($process->getErrorOutput() ?: ($pipe->error()?->getMessage() ?? '')), 0, 300));
        }

        $sha256 = $pipe->sha256();
        $size = $pipe->size();
        $this->disk->put($path.'.json', (string) json_encode([
            'database' => $name,
            'created_at' => $at->utc()->toIso8601String(),
            'size' => $size,
            'sha256' => $sha256,
            'tables' => $tables,
            'estimated' => $estimated,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ['path' => $path, 'size' => $size, 'sha256' => $sha256, 'tables' => $tables, 'estimated' => $estimated];
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
     * Prima di toccare il database di prova controlla che non sia uno di quelli veri e, per i dump nel formato nuovo,
     * che database, ora e ambiente scritti nel dato autenticato corrispondano a ciò che ci si aspetta.
     *
     * @return array{path: string, tables: int, rows: int, seconds: float, legacy: bool}
     */
    public function restoreTest(string $name, ?string $path = null): array
    {
        $started = microtime(true);
        $this->assertRestoreTargetIsSeparate($name);
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
            $opened = BackupCipher::decrypt($in, $out, $this->config->keyPair());
            fclose($in);
            fclose($out);
            if (! $opened['legacy']) {
                $this->assertMatchesManifest($opened['meta'], $manifest, $name, $path);
            }

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
            $estimated = is_array($manifest['estimated'] ?? null) ? array_flip(array_filter($manifest['estimated'], is_string(...))) : [];
            ksort($expected);
            ksort($restored);
            $diff = array_keys(array_diff_key($expected, $restored) + array_diff_key($restored, $expected));
            foreach ($restored as $table => $count) {
                if (isset($expected[$table]) && ! $this->rowsMatch($count, (int) $expected[$table], isset($estimated[$table]))) {
                    $diff[] = $table;
                }
            }
            if ($diff !== []) {
                throw new RuntimeException('Righe ripristinate diverse dal dump: '.implode(', ', array_slice($diff, 0, 10)));
            }

            return ['path' => $path, 'tables' => count($restored), 'rows' => array_sum($restored), 'seconds' => round(microtime(true) - $started, 1), 'legacy' => $opened['legacy']];
        } finally {
            @unlink($plain);
            @unlink($encrypted);
        }
    }

    /**
     * Il database di prova si svuota a ogni ripristino: non deve mai essere uno di quelli veri (stesso server, stessa
     * porta e stesso nome).
     */
    private function assertRestoreTargetIsSeparate(string $name): void
    {
        $target = $this->where('pgsql_restore');
        $real = ['pgsql', 'pgsql_owner', $this->databases->backupConnection($name)];
        foreach ($this->databases->names() as $other) {
            $real[] = $this->databases->backupConnection($other);
            $real[] = $this->databases->ownerConnection($other);
        }
        foreach (array_unique($real) as $connection) {
            if ($this->where($connection) === $target) {
                throw new RuntimeException('Il database di prova coincide con uno di quelli veri: ripristino rifiutato.');
            }
        }
    }

    /** Server, porta e nome del database di una connessione, per confrontare due connessioni. */
    private function where(string $connection): string
    {
        $s = $this->settings($connection);
        $host = strtolower($s['host']);

        return (in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true) ? 'localhost' : $host).'|'.($s['port'] === '' ? '5432' : $s['port']).'|'.$s['database'];
    }

    /**
     * @param  array<string, string>  $meta  intestazione autenticata dentro il file cifrato
     * @param  array<mixed>  $manifest  descrizione accanto al file (non autenticata)
     */
    private function assertMatchesManifest(array $meta, array $manifest, string $name, string $path): void
    {
        $created = $meta['created_at'] ?? '';
        $atInName = preg_match('/-(\d{8}T\d{6}Z)\.dump\.enc$/', $path, $m) === 1
            ? CarbonImmutable::createFromFormat('Ymd\THis\Z', $m[1], 'UTC')
            : null;
        $declared = $manifest['created_at'] ?? null;
        $inside = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $created) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $created, 'UTC')
            : null;

        $declaredDatabase = $manifest['database'] ?? $name;
        if (($meta['database'] ?? null) !== $name || $declaredDatabase !== $name) {
            throw new RuntimeException('Il backup è di un altro database: non corrisponde a quello atteso.');
        }
        if (($meta['env'] ?? null) !== explode('/', $path)[0]) {
            throw new RuntimeException('Il backup è di un altro ambiente: non corrisponde a quello atteso.');
        }
        if (! $inside instanceof CarbonImmutable || ! $atInName instanceof CarbonImmutable || ! $inside->equalTo($atInName)) {
            throw new RuntimeException('L\'ora del backup non corrisponde a quella del nome del file: possibile scambio con un backup vecchio.');
        }
        if (is_string($declared) && ! $inside->equalTo(CarbonImmutable::parse($declared)->utc())) {
            throw new RuntimeException('L\'ora del backup non corrisponde alla descrizione registrata.');
        }
        if ($inside->gt(CarbonImmutable::now('UTC')->addMinutes(5))) {
            throw new RuntimeException('L\'ora del backup è nel futuro.');
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

    /**
     * Righe per tabella senza scorrerle (H35): sotto la soglia (o senza statistiche) si conta davvero, perché costa
     * poco; sopra si usa la stima di PostgreSQL. La prova di ripristino conta sempre davvero.
     *
     * @return array{tables: array<string, int>, estimated: list<string>}
     */
    private function statCounts(ConnectionInterface $connection): array
    {
        $rows = $connection->select(<<<'SQL'
            SELECT n.nspname || '.' || c.relname AS t, c.reltuples::bigint AS e
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind = 'r' AND (n.nspname IN ('core', 'app', 'audit', 'sites', 'portal') OR (n.nspname = 'public' AND c.relname = 'migrations'))
            ORDER BY 1
        SQL);

        $below = $this->config->exactRowsBelow();
        $counts = [];
        $estimated = [];
        foreach ($rows as $row) {
            $table = is_object($row) && isset($row->t) && is_string($row->t) ? $row->t : null;
            if ($table === null || preg_match('/^[a-z_]+\.[a-z0-9_]+$/', $table) !== 1) {
                continue;
            }
            $stat = is_object($row) && isset($row->e) && is_numeric($row->e) ? (int) $row->e : -1;
            if ($stat >= $below) {
                $counts[$table] = $stat;
                $estimated[] = $table;

                continue;
            }
            $count = $connection->scalar("SELECT count(*) FROM {$table}");
            $counts[$table] = is_numeric($count) ? (int) $count : 0;
        }

        return ['tables' => $counts, 'estimated' => $estimated];
    }

    /**
     * Una tabella stimata torna se il numero vero è dello stesso ordine (da metà al doppio, o entro 5000 righe): le
     * statistiche non sono esatte e il dump è un po' più tardi. Le tabelle contate davvero devono tornare uguali.
     */
    private function rowsMatch(int $restored, int $expected, bool $estimated): bool
    {
        if (! $estimated) {
            return $restored === $expected;
        }

        return abs($restored - $expected) <= 5000 || ($restored * 2 >= $expected && $restored <= $expected * 2);
    }

    /** @return Generator<int, string> uscita di pg_dump, a pezzi; l'errore resta nel processo */
    private function dumpOutput(Process $process): Generator
    {
        foreach ($process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
            yield (string) $chunk;
        }
    }

    /**
     * Priorità bassa (H35): `nice` e `ionice` solo se esistono e funzionano su questo server, così il dump non
     * toglie risorse alle richieste degli utenti e un server che non li ammette non blocca il backup.
     *
     * @return list<string>
     */
    private function lowPriority(): array
    {
        if (! $this->config->lowPriority()) {
            return [];
        }
        $finder = new ExecutableFinder;
        $prefix = [];
        foreach ([['nice', '-n', '19'], ['ionice', '-c', '3']] as $tool) {
            $path = $finder->find($tool[0]);
            if ($path === null) {
                continue;
            }
            $probe = new Process([...$prefix, $path, ...array_slice($tool, 1), 'true'], null, null, null, 10);
            $probe->run();
            if ($probe->isSuccessful()) {
                $prefix = [...$prefix, $path, ...array_slice($tool, 1)];
            }
        }

        return $prefix;
    }

    private function discard(string $path): void
    {
        try {
            $this->disk->delete($path);
        } catch (Throwable) {
            // Il file parziale non si apre comunque (manca il blocco finale); si toglie con la pulizia dei vecchi.
        }
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
