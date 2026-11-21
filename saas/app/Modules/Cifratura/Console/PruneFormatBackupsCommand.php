<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Keys\KeyAudit;
use App\Modules\Cifratura\Upgrade\FormatUpgrader;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Toglie le copie d'appoggio di saas:crypt:upgrade-format più vecchie di 30 giorni (ogni giorno da cron). Una riga nel
 * registro delle azioni di ogni associazione dove ha tolto qualcosa, e una nel registro della piattaforma.
 */
#[AsCommand(name: 'saas:crypt:upgrade-format:prune')]
final class PruneFormatBackupsCommand extends Command
{
    protected $signature = 'saas:crypt:upgrade-format:prune {--dry-run : Conta soltanto}';

    protected $description = 'Toglie le copie d\'appoggio del passaggio di formato più vecchie di 30 giorni';

    public function handle(FormatUpgrader $upgrader, OrgContext $context, KeyAudit $platform): int
    {
        $dryRun = $this->option('dry-run') === true;
        $total = 0;
        $failed = 0;
        foreach ($upgrader->orgIds() as $id) {
            try {
                $total += $context->run($id, static fn (): int => $upgrader->prune($dryRun));
            } catch (Throwable $e) {
                $this->error("associazione {$id}: errore (".$e::class.').');
                $failed++;
            }
        }

        $this->line(($dryRun ? 'Da togliere' : 'Tolte')." copie d'appoggio più vecchie di ".FormatUpgrader::RETENTION_DAYS." giorni: {$total}");
        if (! $dryRun && $total > 0) {
            $platform->record(KeyAudit::FORMAT_PRUNE, ['fields_removed' => $total, 'days' => FormatUpgrader::RETENTION_DAYS, 'orgs_failed' => $failed], 'formato dei campi cifrati');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
