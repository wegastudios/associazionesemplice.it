<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Date su più giorni (come nel plugin): giorno di fine facoltativo (vuoto = finisce lo stesso giorno), al massimo 31 giorni.
 * Il vecchio controllo «fine dopo inizio» vale solo per le date di un giorno; su più giorni l'ora di fine è libera.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.activity_sessions ADD COLUMN end_date date');
        DB::statement('ALTER TABLE app.activity_sessions DROP CONSTRAINT activity_sessions_check');
        DB::statement(<<<'SQL'
            ALTER TABLE app.activity_sessions ADD CONSTRAINT activity_sessions_span_check
                CHECK (end_date IS NULL OR (end_date > session_date AND end_date <= session_date + 30))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE app.activity_sessions ADD CONSTRAINT activity_sessions_time_check
                CHECK (end_date IS NOT NULL OR end_time IS NULL OR start_time IS NULL OR end_time > start_time)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.activity_sessions DROP CONSTRAINT activity_sessions_time_check');
        DB::statement('ALTER TABLE app.activity_sessions DROP CONSTRAINT activity_sessions_span_check');
        DB::statement('ALTER TABLE app.activity_sessions ADD CONSTRAINT activity_sessions_check CHECK (end_time IS NULL OR start_time IS NULL OR end_time > start_time)');
        DB::statement('ALTER TABLE app.activity_sessions DROP COLUMN end_date');
    }
};
