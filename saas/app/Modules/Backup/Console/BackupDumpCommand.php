<?php

declare(strict_types=1);

namespace App\Modules\Backup\Console;

use App\Modules\Backup\Backups;
use App\Modules\Core\Contracts\DatabaseNames;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'saas:backup:dump')]
final class BackupDumpCommand extends Command
{
    protected $signature = 'saas:backup:dump {--database=* : Solo questi database}';

    protected $description = 'Dump cifrato di ogni database sullo spazio dei backup';

    public function handle(Backups $backups, DatabaseNames $databases): int
    {
        /** @var list<string> $only */
        $only = (array) $this->option('database');
        $failed = 0;

        foreach ($only === [] ? $databases->names() : $only as $name) {
            try {
                $result = $backups->dump($name, CarbonImmutable::now());
                $this->line(sprintf('ok %s: %s (%d KB, %d righe)', $name, $result['path'], intdiv($result['size'], 1024), array_sum($result['tables'])));
            } catch (Throwable $e) {
                $failed++;
                $this->error("ERRORE {$name}: {$e->getMessage()}");
                report($e);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
