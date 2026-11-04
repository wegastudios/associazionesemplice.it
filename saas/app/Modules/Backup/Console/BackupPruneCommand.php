<?php

declare(strict_types=1);

namespace App\Modules\Backup\Console;

use App\Modules\Backup\Backups;
use App\Modules\Core\Contracts\DatabaseNames;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'saas:backup:prune')]
final class BackupPruneCommand extends Command
{
    protected $signature = 'saas:backup:prune';

    protected $description = 'Tiene 48 ore di dump orari e 30 giorni di dump giornalieri';

    public function handle(Backups $backups, DatabaseNames $databases): int
    {
        foreach ($databases->names() as $name) {
            $this->line("{$name}: cancellati ".$backups->prune($name, CarbonImmutable::now()));
        }

        return self::SUCCESS;
    }
}
