<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSchema;
use App\Modules\Soci\Services\MembershipRealigner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La tessera segue l'anno fiscale (18/11/2026). Una tessera può diventare «sostituita» da un'altra della stessa persona
 * e annualità: resta registrata, con i suoi pagamenti, ma non vale più (la vista delle tessere valide la esclude).
 * Le tessere già emesse degli anni fiscali aperti si riallineano subito, associazione per associazione.
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

        // Per leggere l'elenco delle associazioni la RLS forzata si toglie solo per questa transazione di migrazione.
        DB::statement('ALTER TABLE app.orgs NO FORCE ROW LEVEL SECURITY');
        $ids = DB::table('app.orgs')->pluck('id')->all();
        DB::statement('ALTER TABLE app.orgs FORCE ROW LEVEL SECURITY');

        $context = app(OrgContext::class);
        $realigner = app(MembershipRealigner::class);
        foreach ($ids as $id) {
            if (is_string($id)) {
                $context->run($id, static fn (): array => $realigner->realign());
            }
        }
    }

    public function down(): void {}
};
