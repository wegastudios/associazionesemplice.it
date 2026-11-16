<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Toglie da storage/app/tmp i file di lavoro rimasti (copie, archivi e ricevute in preparazione) quando una richiesta
 * è stata interrotta. Tocca solo ciò che non è stato modificato da più del tempo indicato; con --count non cancella
 * niente e dice solo quanti sono e quanto pesano.
 */
#[AsCommand(name: 'saas:tmp:purge')]
final class PurgeTmpCommand extends Command
{
    protected $signature = 'saas:tmp:purge {--minutes=60 : Età minima in minuti} {--count : Conta soltanto, non cancella}';

    protected $description = 'Toglie i file temporanei rimasti in storage/app/tmp';

    public function handle(): int
    {
        $dir = storage_path('app/tmp');
        $minutes = max(1, (int) $this->option('minutes'));
        [$files, $bytes, $old, $oldBytes] = self::scan($dir, $minutes);
        $this->line("In {$dir}: {$files} file ({$bytes} byte); più vecchi di {$minutes} minuti: {$old} ({$oldBytes} byte).");
        if ($this->option('count')) {
            return self::SUCCESS;
        }
        $removed = self::purge($dir, $minutes);
        $this->line("Tolti {$removed} file.");
        if ($removed > 0) {
            Log::info("saas:tmp:purge: tolti {$removed} file temporanei più vecchi di {$minutes} minuti ({$oldBytes} byte)");
        }

        return self::SUCCESS;
    }

    /** @return array{int, int, int, int} file, byte, file vecchi, byte vecchi */
    public static function scan(string $dir, int $minutes): array
    {
        $out = [0, 0, 0, 0];
        if (! is_dir($dir)) {
            return $out;
        }
        $limit = time() - $minutes * 60;
        foreach (self::files($dir) as $file) {
            $size = (int) @filesize($file);
            $out[0]++;
            $out[1] += $size;
            if ((int) @filemtime($file) < $limit) {
                $out[2]++;
                $out[3] += $size;
            }
        }

        return $out;
    }

    /** Cancella i file più vecchi del limite e le cartelle rimaste vuote; restituisce quanti file ha tolto. */
    public static function purge(string $dir, int $minutes): int
    {
        if (! is_dir($dir)) {
            return 0;
        }
        $limit = time() - $minutes * 60;
        $removed = 0;
        foreach (self::files($dir) as $file) {
            if ((int) @filemtime($file) < $limit && @unlink($file)) {
                $removed++;
            }
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname()); // solo se vuota
            }
        }

        return $removed;
    }

    /** @return iterable<string> */
    private static function files(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }
            if ($entry->isFile() || $entry->isLink()) {
                yield $entry->getPathname();
            }
        }
    }
}
