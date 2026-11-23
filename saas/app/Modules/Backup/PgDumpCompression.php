<?php

declare(strict_types=1);

namespace App\Modules\Backup;

use App\Modules\Backup\Contracts\BackupCompression;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/** zstd se il pg_dump del server la sa fare, altrimenti gzip da solo (H35); la prova si ripete una volta al giorno. */
final readonly class PgDumpCompression implements BackupCompression
{
    public function __construct(private BackupConfig $config) {}

    public function fellBackToGzip(): bool
    {
        return str_starts_with($this->config->compression(), 'zstd') && ! $this->zstdAvailable();
    }

    /** Compressione effettiva: quella configurata, ma gzip:6 se il pg_dump del server non sa fare zstd. */
    public function effective(): string
    {
        return $this->fellBackToGzip() ? 'gzip:6' : $this->config->compression();
    }

    /**
     * Prova a vuoto, in cache per un giorno: pg_dump rifiuta zstd mentre legge le opzioni, prima di collegarsi.
     * Qualunque altro esito (anche un errore di collegamento, o un programma che non parte) vale «c'è».
     */
    private function zstdAvailable(): bool
    {
        $binary = $this->config->binary('pg_dump');
        $known = Cache::remember('backup:zstd:'.hash('sha256', $binary), 86400, function () use ($binary): string {
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
}
