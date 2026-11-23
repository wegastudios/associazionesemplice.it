<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Soglie dell'autovacuum per tabella (H29, docs/DATABASE.md §8). Con i valori di fabbrica la pulizia parte quando le righe
 * morte sono il 20% della tabella e quella delle tabelle solo in aggiunta quando le righe nuove sono il 20%: troppo tardi
 * per le tabelle piccole aggiornate a ogni scrittura (testa del registro, saldi, contatori, sessioni dei soci) e per le
 * grandi in cui si aggiunge e basta (prima nota, ricevute, consensi).
 *
 * `ALTER TABLE ... SET (autovacuum_...)` prende un blocco SHARE UPDATE EXCLUSIVE: letture e scritture passano, e anche il
 * dump orario (ACCESS SHARE). Può aspettare solo un VACUUM o ANALYZE in corso sulla stessa tabella (l'autovacuum si
 * interrompe da sé) o un'altra istruzione di schema: l'attesa è di 3 secondi al massimo, poi la migrazione fallisce senza
 * cambiare niente e si rilancia. Nessun dato si riscrive. La tabella partizionata del registro (audit.log) non accetta
 * questi parametri: andrebbero sulle partizioni, che qui non si toccano.
 */
return new class extends Migration
{
    /** Piccole e aggiornate di continuo: pulizia dopo 100 righe morte più l'1%, senza pause. */
    public const HOT = [
        'autovacuum_vacuum_scale_factor' => '0.01',
        'autovacuum_vacuum_threshold' => '100',
        'autovacuum_analyze_scale_factor' => '0.05',
        'autovacuum_vacuum_cost_delay' => '0',
    ];

    /** Solo in aggiunta: pulizia (e congelamento) dopo 1000 righe nuove più il 5%, statistiche al 2%. */
    public const APPEND = [
        'autovacuum_vacuum_insert_scale_factor' => '0.05',
        'autovacuum_vacuum_insert_threshold' => '1000',
        'autovacuum_analyze_scale_factor' => '0.02',
        'autovacuum_freeze_min_age' => '0',
    ];

    /** @var array<string, 'HOT'|'APPEND'> */
    public const TABLES = [
        'audit.heads' => 'HOT',
        'app.account_balances' => 'HOT',
        'app.counters' => 'HOT',
        'app.matricola_counters' => 'HOT',
        'app.member_sessions' => 'HOT',
        'app.transactions' => 'APPEND',
        'app.receipts' => 'APPEND',
        'app.consent_events' => 'APPEND',
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
