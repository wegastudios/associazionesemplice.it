<?php

declare(strict_types=1);

namespace App\Modules\Soci\Console;

use App\Modules\Cifratura\Contracts\OrgBatch;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Soci\Services\ApplicationService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Cancella le domande di adesione mai confermate (dopo 30 giorni) e quelle respinte (dopo 90). Le accolte restano.
 * Una associazione in errore non ferma le altre.
 */
#[AsCommand(name: 'saas:applications:prune')]
final class PruneApplicationsCommand extends Command
{
    protected $signature = 'saas:applications:prune';

    protected $description = 'Cancella le domande di adesione mai confermate o respinte da tempo';

    public function handle(OrgDirectory $directory, OrgContext $context, OrgBatch $pace): int
    {
        $failed = 0;
        foreach ($pace->paced($directory->activeIds()) as $orgId) {
            try {
                $counts = $context->run($orgId, static fn (): array => app(ApplicationService::class)->prune());
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$orgId}: errore");

                continue;
            }
            if ($counts['unconfirmed'] > 0 || $counts['rejected'] > 0) {
                $this->line($orgId.': '.http_build_query($counts, '', ' '));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
