<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Soglie dell'autovacuum per le tabelle del database condiviso (H29): sessioni, coda e cache, aggiornate o svuotate di
 * continuo, e i registri di sicurezza, solo in aggiunta. Stessi valori e stesso blocco breve della migrazione delle
 * associazioni (Core/Database/Migrations/Org/2027_01_10_000100_autovacuum_per_table.php, che spiega il perché).
 * Le tabelle del portale (schema portal) restano al portale.
 */
return new class extends Migration
{
    public const HOT = [
        'autovacuum_vacuum_scale_factor' => '0.01',
        'autovacuum_vacuum_threshold' => '100',
        'autovacuum_analyze_scale_factor' => '0.05',
        'autovacuum_vacuum_cost_delay' => '0',
    ];

    public const APPEND = [
        'autovacuum_vacuum_insert_scale_factor' => '0.05',
        'autovacuum_vacuum_insert_threshold' => '1000',
        'autovacuum_analyze_scale_factor' => '0.02',
        'autovacuum_freeze_min_age' => '0',
    ];

    /** @var array<string, 'HOT'|'APPEND'> */
    public const TABLES = [
        'core.sessions' => 'HOT',
        'core.jobs' => 'HOT',
        'core.cache' => 'HOT',
        'core.cache_locks' => 'HOT',
        'core.security_events' => 'APPEND',
        'core.platform_audit' => 'APPEND',
        'core.staff_audit' => 'APPEND',
    ];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '3s'");
        foreach (self::TABLES as $table => $profile) {
            $options = $profile === 'HOT' ? self::HOT : self::APPEND;
            $set = implode(', ', array_map(static fn (string $k, string $v): string => "{$k} = {$v}", array_keys($options), $options));
            DB::statement("ALTER TABLE {$table} SET ({$set})");
        }
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '3s'");
        foreach (self::TABLES as $table => $profile) {
            DB::statement("ALTER TABLE {$table} RESET (".implode(', ', array_keys($profile === 'HOT' ? self::HOT : self::APPEND)).')');
        }
    }
};
