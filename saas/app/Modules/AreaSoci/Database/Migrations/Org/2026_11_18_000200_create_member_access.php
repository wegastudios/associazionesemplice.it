<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accesso dei soci all'area soci, senza password (dal MemberSession del plugin): link e codice a 6 cifre per email,
 * oppure un link d'accesso preparato dalla segreteria (per chi non ha email: si manda su WhatsApp). Le sessioni sono
 * nostre, con il token solo come impronta, e si chiudono da sole dopo 30 giorni senza uso o 90 dall'accesso.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.member_login_tokens (
                org_id      uuid        NOT NULL REFERENCES app.orgs(id),
                id          uuid        NOT NULL,
                kind        text        NOT NULL CHECK (kind IN ('email', 'staff')),
                email       citext      CHECK (length(email) <= 254),
                person_id   uuid,
                link_hash   text        NOT NULL CHECK (link_hash ~ '^[0-9a-f]{64}$'),
                code_hash   text        CHECK (code_hash ~ '^[0-9a-f]{64}$'),
                attempts    smallint    NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 100),
                expires_at  timestamptz NOT NULL,
                used_at     timestamptz,
                created_by  uuid,
                created_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id),
                UNIQUE (link_hash),
                FOREIGN KEY (org_id, person_id) REFERENCES app.people(org_id, id),
                CHECK ((kind = 'email') = (email IS NOT NULL AND code_hash IS NOT NULL)),
                CHECK (kind <> 'staff' OR person_id IS NOT NULL)
            )
        SQL);
        DB::statement('CREATE INDEX member_login_tokens_email ON app.member_login_tokens (org_id, email, created_at) WHERE email IS NOT NULL');
        DB::statement('CREATE INDEX member_login_tokens_person ON app.member_login_tokens (org_id, person_id) WHERE person_id IS NOT NULL');
        OrgSchema::isolate('app.member_login_tokens');
        OrgSchema::grant('app.member_login_tokens', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.member_sessions (
                org_id       uuid        NOT NULL REFERENCES app.orgs(id),
                id           uuid        NOT NULL,
                token_hash   text        NOT NULL CHECK (token_hash ~ '^[0-9a-f]{64}$'),
                email        citext      CHECK (length(email) <= 254),
                person_id    uuid        NOT NULL,
                user_agent   text        CHECK (length(user_agent) <= 255),
                created_at   timestamptz NOT NULL DEFAULT now(),
                last_used_at timestamptz NOT NULL DEFAULT now(),
                expires_at   timestamptz NOT NULL,
                revoked_at   timestamptz,
                PRIMARY KEY (org_id, id),
                UNIQUE (token_hash),
                FOREIGN KEY (org_id, person_id) REFERENCES app.people(org_id, id)
            )
        SQL);
        DB::statement('CREATE INDEX member_sessions_person ON app.member_sessions (org_id, person_id)');
        OrgSchema::isolate('app.member_sessions');
        OrgSchema::grant('app.member_sessions', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.member_sessions');
        DB::statement('DROP TABLE app.member_login_tokens');
    }
};
