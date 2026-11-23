<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Log\Partitions;
use App\Modules\Core\Contracts\DatabaseNames;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Crea in anticipo le partizioni mensili del registro su tutti i database (ogni mese, da cron, e a ogni rilascio).
 * Un database che non riesce (per esempio con il backup in corso) non ferma gli altri: alla fine il comando fallisce.
 */
#[AsCommand(name: 'saas:audit:partitions')]
final class AuditPartitionsCommand extends Command
{
    protected $signature = 'saas:audit:partitions {--months=3 : Mesi da preparare, compreso quello corrente}';

    protected $description = 'Crea le partizioni mensili del registro delle azioni';

    public function handle(DatabaseNames $databases, DatabaseManager $db): int
    {
        $months = max(1, (int) $this->option('months'));

        $failed = false;
        foreach ($databases->names() as $name) {
            try {
                Partitions::ensure($db->connection($databases->ownerConnection($name)), now(), $months);
                $this->line("ok {$name}");
            } catch (Throwable $e) {
                report($e);
                $failed = true;
                $this->error("ERRORE {$name}: partizioni non preparate (".class_basename($e).' '.$e->getCode().'), si riprova al prossimo giro');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
