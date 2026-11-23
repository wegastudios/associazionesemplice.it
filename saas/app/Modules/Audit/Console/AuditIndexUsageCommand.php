<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Core\Contracts\DatabaseNames;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Letture degli indici del registro, sommate su tutte le partizioni, per ogni database (sola lettura, con il ruolo dei
 * backup). Serve a confermare che un indice non lo legge nessuno prima di toglierlo (H28: `audit.log_org_subject`).
 * I conteggi valgono dall'ultimo azzeramento delle statistiche, che si stampa: un periodo breve non prova niente.
 */
#[AsCommand(name: 'saas:audit:index-usage')]
final class AuditIndexUsageCommand extends Command
{
    protected $signature = 'saas:audit:index-usage';

    protected $description = 'Letture degli indici del registro delle azioni, su tutte le partizioni e tutti i database';

    public function handle(DatabaseNames $databases, DatabaseManager $db): int
    {
        $failed = 0;
        foreach ($databases->names() as $name) {
            try {
                $connection = $db->connection($databases->backupConnection($name));
                $since = $connection->selectOne('SELECT coalesce(stats_reset::text, ?) AS d FROM pg_stat_database WHERE datname = current_database()', ['mai']);
                $this->line("== {$name} (statistiche dal: ".(is_object($since) && is_string($since->d ?? null) ? $since->d : 'mai').')');
                // Gli indici della tabella partizionata non hanno statistiche proprie: si sommano quelli delle partizioni.
                $rows = $connection->select(<<<'SQL'
                    SELECT p.relname AS idx, count(s.indexrelid) AS partitions, coalesce(sum(s.idx_scan), 0) AS scans,
                           coalesce(sum(pg_relation_size(s.indexrelid)), 0) AS bytes
                    FROM pg_index pi
                    JOIN pg_class p ON p.oid = pi.indexrelid
                    LEFT JOIN pg_inherits i ON i.inhparent = pi.indexrelid
                    LEFT JOIN pg_stat_user_indexes s ON s.indexrelid = i.inhrelid
                    WHERE pi.indrelid = 'audit.log'::regclass
                    GROUP BY p.relname
                    ORDER BY p.relname
                SQL);
                foreach ($rows as $r) {
                    /** @var object{idx: string, partitions: int|string, scans: int|string, bytes: int|string} $r */
                    $mb = round(((float) $r->bytes) / 1048576, 1);
                    $this->line("{$r->idx}: letture {$r->scans}, partizioni {$r->partitions}, {$mb} MB".((int) $r->scans === 0 ? '  <-- mai letto' : ''));
                }
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$name}: non disponibile (".$e::class.')');
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
