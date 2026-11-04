<?php

declare(strict_types=1);

namespace App\Modules\Backup\Console;

use App\Modules\Backup\Backups;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/** Prova di ripristino: solo dove c'è la chiave privata dei backup (staging), mai in produzione. */
#[AsCommand(name: 'saas:backup:restore-test')]
final class BackupRestoreTestCommand extends Command
{
    protected $signature = 'saas:backup:restore-test
        {--database=shared : Database di cui provare il backup}
        {--file= : Percorso del dump nello spazio dei backup (vuoto = il più recente)}';

    protected $description = 'Ripristina un backup nel database di prova e controlla le righe';

    public function handle(Backups $backups): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('La prova di ripristino non si fa in produzione: lì non deve esserci la chiave privata.');

            return self::FAILURE;
        }

        $file = $this->option('file');

        try {
            $r = $backups->restoreTest((string) $this->option('database'), is_string($file) && $file !== '' ? $file : null);
        } catch (Throwable $e) {
            $this->error('Ripristino NON riuscito: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info("Ripristino riuscito: {$r['path']} · {$r['tables']} tabelle · {$r['rows']} righe · {$r['seconds']} s");

        return self::SUCCESS;
    }
}
