<?php

declare(strict_types=1);

namespace App\Modules\Portale\Console;

use App\Modules\Portale\Contracts\UsageHealth;
use App\Modules\Portale\Usage\UsageReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

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

        try {
            $result = $report->send();
        } catch (Throwable $e) {
            UsageHealth::record(false);

            throw $e;
        }
        UsageHealth::record(true);
        // Una riga per invio in storage/logs/portal-usage.log (lo scheduler ci aggiunge l'uscita): solo conteggi, mai i numeri dei soci.
        $this->info(sprintf('%s inviate %d, salvate %s, non riconosciute %s', now()->toIso8601String(), $result['sent'],
            $result['saved'] ?? '?', $result['unknown'] ?? '?'));
        if (($result['unknown'] ?? 0) > 0) {
            Log::warning('Consumi: il portale non ha riconosciuto alcune associazioni', $result);
        }

        return self::SUCCESS;
    }
}
