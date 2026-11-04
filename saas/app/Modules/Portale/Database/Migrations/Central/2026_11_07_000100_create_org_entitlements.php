<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Diritti firmati dal portale (CONTRATTO_PORTALE.md). Si conserva il token così com'è arrivato, per poterlo
 * riverificare, e i suoi dati già letti. `portal_ref` lega l'associazione all'abbonamento del portale.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE core.orgs ADD COLUMN portal_ref text');
        DB::statement("ALTER TABLE core.orgs ADD CONSTRAINT orgs_portal_ref_format CHECK (portal_ref ~ '^[A-Za-z0-9_-]{1,64}$')");
        DB::statement('CREATE UNIQUE INDEX orgs_portal_ref_unique ON core.orgs (portal_ref) WHERE portal_ref IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE core.org_entitlements (
                org_id      uuid        NOT NULL REFERENCES core.orgs(id),
                rev         bigint      NOT NULL CHECK (rev > 0),
                status      text        NOT NULL CHECK (status IN ('trial', 'active', 'past_due', 'suspended', 'cancelled')),
                until       date,
                claims      jsonb       NOT NULL,
                token       text        NOT NULL,
                received_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id)
            )
        SQL);
        OrgSchema::grant('core.org_entitlements', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.org_entitlements');
        DB::statement('ALTER TABLE core.orgs DROP COLUMN portal_ref');
    }
};
