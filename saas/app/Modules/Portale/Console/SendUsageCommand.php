<?php

declare(strict_types=1);

namespace App\Modules\Portale\Console;

use App\Modules\Portale\Usage\UsageReport;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'saas:portal:usage')]
final class SendUsageCommand extends Command
{
    protected $signature = 'saas:portal:usage {--dry-run : Mostra i numeri senza inviarli}';

    protected $description = 'Comunica al portale soci e operatori di ogni associazione';

    public function handle(UsageReport $report): int
    {
        if ($this->option('dry-run')) {
            $this->table(['associazione', 'abbonamento', 'soci', 'operatori', 'fatture'], $report->collect());

            return self::SUCCESS;
        }

        $this->info('Associazioni comunicate: '.$report->send());

        return self::SUCCESS;
    }
}
