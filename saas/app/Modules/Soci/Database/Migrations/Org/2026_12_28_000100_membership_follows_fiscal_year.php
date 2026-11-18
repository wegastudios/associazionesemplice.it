<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La tessera segue l'anno fiscale (18/11/2026). Una tessera può diventare «sostituita» da un'altra della stessa persona
 * e annualità: resta registrata, con i suoi pagamenti, ma non vale più (la vista delle tessere valide la esclude).
 * Il riallineamento non è una migrazione: parte solo davanti a un cambio, con anteprima e conferma.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.memberships ADD COLUMN superseded_at timestamptz, ADD COLUMN superseded_by uuid');
        DB::statement('ALTER TABLE app.memberships ADD CONSTRAINT memberships_superseded_check CHECK ((superseded_at IS NULL) = (superseded_by IS NULL))');
        DB::statement('GRANT UPDATE (social_year, valid_from, valid_to, superseded_at, superseded_by) ON app.memberships TO '.OrgSchema::appRole());

        DB::statement('DROP INDEX app.memberships_person_year');
        DB::statement('CREATE UNIQUE INDEX memberships_person_year ON app.memberships (org_id, person_id, social_year) WHERE voided_at IS NULL AND superseded_at IS NULL');

        DB::statement('DROP VIEW app.valid_memberships');
        DB::statement(<<<'SQL'
            CREATE VIEW app.valid_memberships WITH (security_invoker = true) AS
            SELECT m.*
            FROM app.memberships m
            LEFT JOIN app.transactions t ON t.org_id = m.org_id AND t.id = m.transaction_id
            WHERE m.voided_at IS NULL AND m.superseded_at IS NULL AND (m.transaction_id IS NULL OR t.voided_at IS NULL)
        SQL);
        DB::statement('GRANT SELECT ON app.valid_memberships TO '.OrgSchema::appRole());
    }

    public function down(): void {}
};
