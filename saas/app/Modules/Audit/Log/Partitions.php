<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use App\Modules\Core\Contracts\OrgSchema;
use App\Modules\Core\Contracts\ShortLocks;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Partizioni mensili di audit.log, create in anticipo (saas:audit:partitions, ogni mese) con il ruolo proprietario.
 * La partizione predefinita raccoglie ciò che cadesse fuori: una scrittura nel registro non fallisce mai per questo.
 * Deve però restare vuota (saas:health lo controlla): agganciare un mese nuovo la rilegge tutta con il blocco forte.
 * Ogni partizione ha la sua Row-Level Security, anche se il ruolo dell'applicazione non vi accede direttamente.
 *
 * Il mese nuovo nasce come tabella a sé e poi si aggancia (ATTACH PARTITION): audit.log resta scrivibile durante
 * l'aggancio, che non si mette in fila dietro il backup orario più di qualche secondo (ShortLocks).
 */
final class Partitions
{
    public static function ensure(ConnectionInterface $owner, DateTimeInterface $from, int $months): void
    {
        if (! self::exists($owner, 'audit.log_default')) {
            ShortLocks::run($owner, static function () use ($owner): void {
                $owner->statement('CREATE TABLE audit.log_default PARTITION OF audit.log DEFAULT');
                OrgSchema::secure($owner, 'audit.log_default');
            });
        }

        $start = Carbon::instance($from)->utc()->startOfMonth();
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $table = 'audit.log_'.$month->format('Y_m');
            if (self::exists($owner, $table)) {
                continue;
            }
            $bounds = "FOR VALUES FROM ('{$month->format('Y-m-d')} 00:00:00+00') TO ('{$month->copy()->addMonth()->format('Y-m-d')} 00:00:00+00')";
            ShortLocks::run($owner, static function () use ($owner, $table, $bounds): void {
                $owner->statement("CREATE TABLE {$table} (LIKE audit.log INCLUDING DEFAULTS INCLUDING CONSTRAINTS)");
                OrgSchema::secure($owner, $table);
                $owner->statement("ALTER TABLE audit.log ATTACH PARTITION {$table} {$bounds}");
            });
        }
    }

    private static function exists(ConnectionInterface $owner, string $table): bool
    {
        $exists = $owner->selectOne('SELECT to_regclass(?) IS NOT NULL AS ok', [$table]);

        return is_object($exists) && ($exists->ok ?? false) === true;
    }
}
