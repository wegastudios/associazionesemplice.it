<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eventi di sicurezza degli utenti (accessi, tentativi falliti, verifica in due passaggi, password): non appartengono a
 * un'associazione, quindi stanno nel database centrale. Solo in aggiunta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.security_events (
                id          uuid        PRIMARY KEY,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                event       text        NOT NULL CHECK (event ~ '^[a-z_]+(\.[a-z_]+)+$'),
                user_id     uuid        REFERENCES core.users(id),
                ip          text        CHECK (length(ip) <= 45),
                user_agent  text        CHECK (length(user_agent) <= 255),
                details     jsonb       NOT NULL DEFAULT '{}'
            )
        SQL);
        DB::statement('CREATE INDEX security_events_user ON core.security_events (user_id, occurred_at)');
        DB::statement('CREATE INDEX security_events_time ON core.security_events (occurred_at)');

        DB::statement(<<<'SQL'
            CREATE FUNCTION core.reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Gli eventi di sicurezza sono solo in aggiunta';
            END
            $$
        SQL);
        DB::statement('CREATE TRIGGER security_events_append_only BEFORE UPDATE OR DELETE ON core.security_events FOR EACH ROW EXECUTE FUNCTION core.reject_change()');

        OrgSchema::grant('core.security_events', ['SELECT', 'INSERT']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.security_events');
        DB::statement('DROP FUNCTION core.reject_change()');
    }
};
