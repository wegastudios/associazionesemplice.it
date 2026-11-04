<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Log\Partitions;
use App\Modules\Core\Contracts\DatabaseNames;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;

/** Crea in anticipo le partizioni mensili del registro su tutti i database (ogni mese, da cron). */
#[AsCommand(name: 'saas:audit:partitions')]
final class AuditPartitionsCommand extends Command
{
    protected $signature = 'saas:audit:partitions {--months=3 : Mesi da preparare, compreso quello corrente}';

    protected $description = 'Crea le partizioni mensili del registro delle azioni';

    public function handle(DatabaseNames $databases, DatabaseManager $db): int
    {
        $months = max(1, (int) $this->option('months'));

        foreach ($databases->names() as $name) {
            Partitions::ensure($db->connection($databases->ownerConnection($name)), now(), $months);
            $this->line("ok {$name}");
        }

        return self::SUCCESS;
    }
}
