<?php

declare(strict_types=1);

use App\Modules\Audit\Log\Partitions;
use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registro delle azioni di un'associazione (DATABASE.md §7): solo in aggiunta, partizionato per mese, ogni riga
 * sigillata insieme al sigillo della precedente (catena). audit.heads tiene la testa della catena e serializza le
 * scritture della stessa associazione.
 *
 * Nel registro non vanno mai valori personali: solo chi, cosa, su quale scheda e quali campi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE audit.heads (
                org_id     uuid        NOT NULL REFERENCES app.orgs(id),
                seq        bigint      NOT NULL CHECK (seq >= 0),
                hash       bytea       NOT NULL,
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id)
            )
        SQL);
        OrgSchema::isolate('audit.heads');
        OrgSchema::grant('audit.heads', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE audit.log (
                org_id       uuid        NOT NULL REFERENCES app.orgs(id),
                occurred_at  timestamptz NOT NULL,
                seq          bigint      NOT NULL CHECK (seq > 0),
                actor_type   text        NOT NULL CHECK (actor_type IN ('user', 'system')),
                actor_id     uuid,
                action       text        NOT NULL CHECK (action ~ '^[a-z_]+(\.[a-z_]+)+$'),
                subject_type text        CHECK (subject_type ~ '^[a-z_]+(\.[a-z_]+)?$'),
                subject_id   uuid,
                ip           text        CHECK (length(ip) <= 45),
                details      jsonb       NOT NULL DEFAULT '{}',
                hash         bytea       NOT NULL,
                PRIMARY KEY (org_id, occurred_at, seq)
            ) PARTITION BY RANGE (occurred_at)
        SQL);
        DB::statement('CREATE INDEX log_org_seq ON audit.log (org_id, seq)');
        DB::statement('CREATE INDEX log_org_subject ON audit.log (org_id, subject_type, subject_id, occurred_at)');

        // Nemmeno il proprietario modifica o cancella righe senza prima togliere il trigger.
        DB::statement(<<<'SQL'
            CREATE FUNCTION audit.reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Il registro delle azioni è solo in aggiunta';
            END
            $$
        SQL);
        DB::statement('CREATE TRIGGER log_append_only BEFORE UPDATE OR DELETE ON audit.log FOR EACH ROW EXECUTE FUNCTION audit.reject_change()');

        OrgSchema::isolate('audit.log');
        OrgSchema::grant('audit.log', ['SELECT', 'INSERT']);

        Partitions::ensure(DB::connection(), now(), 3);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE audit.log');
        DB::statement('DROP FUNCTION audit.reject_change()');
        DB::statement('DROP TABLE audit.heads');
    }
};
