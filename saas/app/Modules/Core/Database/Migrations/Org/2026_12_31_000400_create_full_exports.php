<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copie di sicurezza complete chieste dagli operatori: si preparano in coda e si scaricano quando sono pronte. Qui solo
 * lo stato (chi l'ha chiesta, a che punto è, quanti file): nessun dato dei soci. L'archivio sta su disco, in una
 * cartella privata, e si cancella alla scadenza.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.full_exports (
                org_id            uuid        NOT NULL REFERENCES app.orgs(id),
                id                uuid        NOT NULL,
                user_id           uuid        NOT NULL,
                status            text        NOT NULL CHECK (status IN ('queued', 'running', 'ready', 'failed')),
                year              integer,
                with_certificates boolean     NOT NULL DEFAULT false,
                done              integer     NOT NULL DEFAULT 0,
                total             integer     NOT NULL DEFAULT 0,
                files             integer     NOT NULL DEFAULT 0,
                certificates      integer     NOT NULL DEFAULT 0,
                error             text,
                created_at        timestamptz NOT NULL DEFAULT now(),
                started_at        timestamptz,
                finished_at       timestamptz,
                expires_at        timestamptz,
                PRIMARY KEY (org_id, id)
            )
        SQL);
        DB::statement('CREATE INDEX full_exports_org_user_idx ON app.full_exports (org_id, user_id, created_at DESC)');
        OrgSchema::isolate('app.full_exports');
        OrgSchema::grant('app.full_exports', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.full_exports');
    }
};
