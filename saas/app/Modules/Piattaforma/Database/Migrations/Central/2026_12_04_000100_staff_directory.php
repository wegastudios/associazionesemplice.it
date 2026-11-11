<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elenco unico dello staff (docs/UTENTI_E_ACCESSI.md, revisione dell'11/11/2026): il SaaS è il fornitore di identità
 * per WordPress, SaaS e Supporto. Solo tabelle nuove: nulla di quanto esiste cambia (migrazioni in due tempi).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.staff_people (
                id            uuid        PRIMARY KEY,
                email         text        NOT NULL UNIQUE CHECK (email = lower(email) AND length(email) <= 254),
                name          text        NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
                password      text,
                active        boolean     NOT NULL DEFAULT true,
                last_login_at timestamptz,
                created_at    timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        OrgSchema::grant('core.staff_people', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE core.staff_roles (
                person_id  uuid        NOT NULL REFERENCES core.staff_people(id) ON DELETE CASCADE,
                area       text        NOT NULL CHECK (area IN ('wordpress', 'saas', 'supporto')),
                level      text        NOT NULL,
                granted_by uuid        REFERENCES core.staff_people(id),
                granted_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (person_id, area),
                CHECK ((area = 'supporto' AND level IN ('admin', 'agente')) OR (area <> 'supporto' AND level IN ('admin', 'vendita', 'supporto')))
            )
        SQL);
        OrgSchema::grant('core.staff_roles', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        DB::statement(<<<'SQL'
            CREATE TABLE core.staff_sessions (
                sid          text        PRIMARY KEY CHECK (sid ~ '^[0-9a-f]{32}$'),
                person_id    uuid        NOT NULL REFERENCES core.staff_people(id) ON DELETE CASCADE,
                ip           inet,
                created_at   timestamptz NOT NULL DEFAULT now(),
                last_used_at timestamptz NOT NULL DEFAULT now(),
                revoked_at   timestamptz
            )
        SQL);
        OrgSchema::grant('core.staff_sessions', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE core.staff_audit (
                id         uuid        PRIMARY KEY,
                person_id  uuid        REFERENCES core.staff_people(id),
                action     text        NOT NULL CHECK (action ~ '^[a-z_]+(\.[a-z_]+)+$'),
                subject    text,
                details    jsonb       NOT NULL DEFAULT '{}',
                ip         inet,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX staff_audit_created ON core.staff_audit (created_at DESC)');
        OrgSchema::grant('core.staff_audit', ['SELECT', 'INSERT']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.staff_audit');
        DB::statement('DROP TABLE core.staff_sessions');
        DB::statement('DROP TABLE core.staff_roles');
        DB::statement('DROP TABLE core.staff_people');
    }
};
