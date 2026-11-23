<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Console;

use App\Modules\AreaSoci\Services\MemberAccess;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Ogni giorno cancella i link d'accesso, i codici e le sessioni dell'area soci che non valgono più da 30 giorni, a
 * blocchi (MemberAccess::prune). Una associazione in errore non ferma le altre.
 */
#[AsCommand(name: 'saas:member-access:prune')]
final class PruneMemberAccessCommand extends Command
{
    protected $signature = 'saas:member-access:prune';

    protected $description = 'Cancella link, codici e sessioni dell\'area soci scaduti da 30 giorni';

    public function handle(OrgDirectory $directory, OrgContext $context): int
    {
        $failed = 0;
        foreach ($directory->activeIds() as $orgId) {
            try {
                $counts = $context->run($orgId, static fn (): array => app(MemberAccess::class)->prune());
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$orgId}: errore");

                continue;
            }
            if ($counts['tokens'] > 0 || $counts['sessions'] > 0) {
                $this->line($orgId.': '.http_build_query($counts, '', ' '));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
