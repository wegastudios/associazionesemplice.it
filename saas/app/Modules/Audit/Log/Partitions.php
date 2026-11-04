<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Partizioni mensili di audit.log, create in anticipo (saas:audit:partitions, ogni mese) con il ruolo proprietario.
 * La partizione predefinita raccoglie ciò che cadesse fuori: una scrittura nel registro non fallisce mai per questo.
 * Ogni partizione ha la sua Row-Level Security, anche se il ruolo dell'applicazione non vi accede direttamente.
 */
final class Partitions
{
    public static function ensure(ConnectionInterface $owner, DateTimeInterface $from, int $months): void
    {
        self::create($owner, 'audit.log_default', 'DEFAULT');

        $start = Carbon::instance($from)->utc()->startOfMonth();
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $next = $month->copy()->addMonth();
            self::create(
                $owner,
                'audit.log_'.$month->format('Y_m'),
                "FOR VALUES FROM ('{$month->format('Y-m-d')} 00:00:00+00') TO ('{$next->format('Y-m-d')} 00:00:00+00')",
            );
        }
    }

    private static function create(ConnectionInterface $owner, string $table, string $bounds): void
    {
        $exists = $owner->selectOne('SELECT to_regclass(?) IS NOT NULL AS ok', [$table]);
        if (is_object($exists) && ($exists->ok ?? false) === true) {
            return;
        }

        $owner->statement("CREATE TABLE {$table} PARTITION OF audit.log {$bounds}");
        $owner->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        $owner->statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        $owner->statement("CREATE POLICY org_isolation ON {$table} USING (org_id = NULLIF(current_setting('app.org_id', true), '')::uuid) WITH CHECK (org_id = NULLIF(current_setting('app.org_id', true), '')::uuid)");
    }
}
