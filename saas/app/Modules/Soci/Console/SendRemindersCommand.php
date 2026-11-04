<?php

declare(strict_types=1);

namespace App\Modules\Soci\Console;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Soci\Services\ReminderService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/** Promemoria delle scadenze per ogni associazione attiva che li ha accesi. Una associazione in errore non ferma le altre. */
#[AsCommand(name: 'saas:reminders:send')]
final class SendRemindersCommand extends Command
{
    protected $signature = 'saas:reminders:send {--dry-run : Conta i promemoria senza mandarli}';

    protected $description = 'Manda i promemoria di tessere e certificati medici in scadenza';

    public function handle(OrgDirectory $directory, OrgContext $context): int
    {
        $today = now()->toDateString();
        $send = $this->option('dry-run') !== true;
        $failed = 0;
        foreach ($directory->activeIds() as $orgId) {
            try {
                $counts = $context->run($orgId, static function () use ($today, $send): ?array {
                    $reminders = app(ReminderService::class);

                    return $reminders->enabled() ? $reminders->run($today, $send) : null;
                });
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$orgId}: errore");

                continue;
            }
            if ($counts !== null) {
                $this->line($orgId.': '.http_build_query($counts, '', ' '));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
