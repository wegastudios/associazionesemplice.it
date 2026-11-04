<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ruoli configurabili per segretari e consiglieri: ogni associazione crea i suoi (nome e permessi) e li assegna agli
 * operatori con il ruolo «custom». I ruoli fissi (titolare, amministratore, segreteria, commercialista) restano.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.org_roles (
                id         uuid        PRIMARY KEY,
                org_id     uuid        NOT NULL REFERENCES core.orgs(id),
                name       text        NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 60),
                abilities  text[]      NOT NULL DEFAULT '{}',
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (org_id, id)
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX org_roles_name ON core.org_roles (org_id, lower(btrim(name)))');
        OrgSchema::grant('core.org_roles', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        DB::statement('ALTER TABLE core.org_users ADD COLUMN custom_role_id uuid');
        DB::statement('ALTER TABLE core.org_users ADD CONSTRAINT org_users_custom_role_fk FOREIGN KEY (org_id, custom_role_id) REFERENCES core.org_roles(org_id, id)');
        DB::statement('CREATE INDEX org_users_custom_role ON core.org_users (org_id, custom_role_id) WHERE custom_role_id IS NOT NULL');
        DB::statement('ALTER TABLE core.org_users DROP CONSTRAINT org_users_role_check');
        DB::statement("ALTER TABLE core.org_users ADD CONSTRAINT org_users_role_check CHECK (role IN ('owner', 'admin', 'operator', 'accountant', 'custom'))");
        DB::statement("ALTER TABLE core.org_users ADD CONSTRAINT org_users_custom_role_check CHECK ((role = 'custom') = (custom_role_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE core.org_users DROP CONSTRAINT org_users_custom_role_check');
        DB::statement('ALTER TABLE core.org_users DROP CONSTRAINT org_users_role_check');
        DB::statement("ALTER TABLE core.org_users ADD CONSTRAINT org_users_role_check CHECK (role IN ('owner', 'admin', 'operator', 'accountant'))");
        DB::statement('ALTER TABLE core.org_users DROP COLUMN custom_role_id');
        DB::statement('DROP TABLE core.org_roles');
    }
};
