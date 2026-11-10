<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Staff dell'amministrazione della piattaforma (/saas/admin): persone diverse dagli operatori delle associazioni, con
 * una tabella, un accesso e un registro propri. Non sono dati di un'associazione: niente `org_id`, schema `core`
 * (DATABASE.md §10). Il registro non si modifica e non si cancella: il ruolo dell'applicazione può solo aggiungere.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.platform_staff (
                id                        uuid        PRIMARY KEY,
                email                     citext      NOT NULL UNIQUE,
                name                      text        NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 120),
                password                  text        NOT NULL,
                role                      text        NOT NULL CHECK (role IN ('admin', 'readonly')),
                two_factor_secret         text,
                two_factor_recovery_codes text,
                two_factor_confirmed_at   timestamptz,
                disabled_at               timestamptz,
                last_login_at             timestamptz,
                created_at                timestamptz NOT NULL DEFAULT now(),
                updated_at                timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        OrgSchema::grant('core.platform_staff', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE core.platform_audit (
                id         uuid        PRIMARY KEY,
                staff_id   uuid        REFERENCES core.platform_staff(id),
                action     text        NOT NULL CHECK (action ~ '^[a-z_]+(\.[a-z_]+)+$'),
                subject    text,
                details    jsonb       NOT NULL DEFAULT '{}',
                ip         inet,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX platform_audit_created ON core.platform_audit (created_at DESC)');
        OrgSchema::grant('core.platform_audit', ['SELECT', 'INSERT']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.platform_audit');
        DB::statement('DROP TABLE core.platform_staff');
    }
};
