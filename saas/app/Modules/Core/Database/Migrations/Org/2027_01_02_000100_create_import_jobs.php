<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Import da file in coda (Core\Contracts\ImportJobs, H10 e H11): l'elenco da importare e lo stato (esito, errori) sono
 * cifrati con la chiave dell'associazione, perché contengono dati personali. done = righe già importate, nella stessa
 * transazione delle righe: il lavoro si riprende da lì. Un solo import attivo per tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.import_jobs (
                org_id      uuid        NOT NULL REFERENCES app.orgs(id),
                id          uuid        NOT NULL,
                kind        text        NOT NULL CHECK (kind IN ('people', 'ledger')),
                status      text        NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed', 'cancelled')),
                total       integer     NOT NULL CHECK (total >= 0),
                done        integer     NOT NULL DEFAULT 0 CHECK (done BETWEEN 0 AND total),
                options     jsonb       NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(options) = 'object'),
                plan_enc    bytea,
                state_enc   bytea       NOT NULL,
                user_id     uuid,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                started_at  timestamptz,
                finished_at timestamptz,
                PRIMARY KEY (org_id, id),
                CHECK ((status IN ('done', 'cancelled')) = (finished_at IS NOT NULL))
            )
        SQL);
        DB::statement("CREATE UNIQUE INDEX import_jobs_one_active ON app.import_jobs (org_id, kind) WHERE status IN ('queued', 'running')");
        DB::statement('CREATE INDEX import_jobs_latest ON app.import_jobs (org_id, kind, created_at)');
        OrgSchema::isolate('app.import_jobs');
        OrgSchema::grant('app.import_jobs', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.import_jobs');
    }
};
