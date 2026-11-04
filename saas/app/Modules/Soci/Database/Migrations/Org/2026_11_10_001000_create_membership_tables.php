<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anagrafica completa e tesseramento (dal plugin: people, member_levels, memberships, guardians).
 *
 * - Una tessera è valida se non è stornata e, quando nasce da un incasso, se quell'incasso non è stornato: stornare
 *   la quota in prima nota toglie la tessera, senza passaggi da ricordare.
 * - Le note possono contenere dati personali: si cifrano come gli altri campi sensibili.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.member_levels (
                org_id     uuid        NOT NULL REFERENCES app.orgs(id),
                id         uuid        NOT NULL,
                name       text        NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
                base_type  text        NOT NULL CHECK (base_type IN ('founder', 'ordinary', 'volunteer')),
                fee_cents  bigint      CHECK (fee_cents >= 0),
                sort_order integer     NOT NULL DEFAULT 0,
                active     boolean     NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id)
            )
        SQL);
        OrgSchema::isolate('app.member_levels');
        OrgSchema::grant('app.member_levels', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            ALTER TABLE app.people
                ADD COLUMN type               text NOT NULL DEFAULT 'ordinary'
                    CHECK (type IN ('founder', 'ordinary', 'volunteer', 'guest', 'guardian')),
                ADD COLUMN level_id           uuid,
                ADD COLUMN host_person_id     uuid,
                ADD COLUMN joined_on          date,
                ADD COLUMN left_on            date,
                ADD COLUMN left_reason        text CHECK (length(left_reason) <= 190),
                ADD COLUMN suspended_at       timestamptz,
                ADD COLUMN privacy_consent_at timestamptz,
                ADD COLUMN anonymized_at      timestamptz,
                ADD COLUMN notes_enc          bytea,
                ADD CONSTRAINT people_level_fk FOREIGN KEY (org_id, level_id) REFERENCES app.member_levels(org_id, id),
                ADD CONSTRAINT people_host_fk  FOREIGN KEY (org_id, host_person_id) REFERENCES app.people(org_id, id),
                ADD CONSTRAINT people_host_only_guests CHECK ((type = 'guest') = (host_person_id IS NOT NULL) OR anonymized_at IS NOT NULL),
                ADD CONSTRAINT people_guests_no_card CHECK (type <> 'guest' OR card_number IS NULL)
        SQL);
        DB::statement('CREATE INDEX people_level ON app.people (org_id, level_id) WHERE level_id IS NOT NULL');
        DB::statement('CREATE INDEX people_host ON app.people (org_id, host_person_id) WHERE host_person_id IS NOT NULL');
        DB::statement('CREATE INDEX people_type ON app.people (org_id, type) WHERE deleted_at IS NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE app.memberships (
                org_id         uuid        NOT NULL REFERENCES app.orgs(id),
                id             uuid        NOT NULL,
                person_id      uuid        NOT NULL,
                social_year    text        NOT NULL CHECK (social_year ~ '^\d{4}(/\d{4})?$'),
                valid_from     date        NOT NULL,
                valid_to       date        NOT NULL,
                source         text        NOT NULL CHECK (source IN ('payment', 'manual', 'founder', 'import')),
                transaction_id uuid,
                created_by     uuid,
                created_at     timestamptz NOT NULL DEFAULT now(),
                voided_at      timestamptz,
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, person_id)      REFERENCES app.people(org_id, id),
                FOREIGN KEY (org_id, transaction_id) REFERENCES app.transactions(org_id, id),
                CHECK (valid_to >= valid_from),
                CHECK ((source = 'payment') = (transaction_id IS NOT NULL))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX memberships_person_year ON app.memberships (org_id, person_id, social_year) WHERE voided_at IS NULL');
        DB::statement('CREATE INDEX memberships_person ON app.memberships (org_id, person_id, valid_to)');
        DB::statement('CREATE INDEX memberships_transaction ON app.memberships (org_id, transaction_id) WHERE transaction_id IS NOT NULL');
        OrgSchema::isolate('app.memberships');
        OrgSchema::grant('app.memberships', ['SELECT', 'INSERT']);
        DB::statement('GRANT UPDATE (voided_at) ON app.memberships TO '.OrgSchema::appRole());

        DB::statement(<<<'SQL'
            CREATE TABLE app.guardians (
                org_id             uuid        NOT NULL REFERENCES app.orgs(id),
                person_id          uuid        NOT NULL,
                guardian_person_id uuid        NOT NULL,
                is_operative       boolean     NOT NULL DEFAULT true,
                created_at         timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, person_id, guardian_person_id),
                FOREIGN KEY (org_id, person_id)          REFERENCES app.people(org_id, id),
                FOREIGN KEY (org_id, guardian_person_id) REFERENCES app.people(org_id, id),
                CHECK (person_id <> guardian_person_id)
            )
        SQL);
        DB::statement('CREATE INDEX guardians_guardian ON app.guardians (org_id, guardian_person_id)');
        OrgSchema::isolate('app.guardians');
        OrgSchema::grant('app.guardians', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        // Tessere valide oggi: non stornate, e con l'incasso della quota non stornato.
        DB::statement(<<<'SQL'
            CREATE VIEW app.valid_memberships WITH (security_invoker = true) AS
            SELECT m.*
            FROM app.memberships m
            LEFT JOIN app.transactions t ON t.org_id = m.org_id AND t.id = m.transaction_id
            WHERE m.voided_at IS NULL AND (m.transaction_id IS NULL OR t.voided_at IS NULL)
        SQL);
        DB::statement('GRANT SELECT ON app.valid_memberships TO '.OrgSchema::appRole());
    }

    public function down(): void
    {
        DB::statement('DROP VIEW app.valid_memberships');
        DB::statement('DROP TABLE app.guardians');
        DB::statement('DROP TABLE app.memberships');
        DB::statement('ALTER TABLE app.people DROP CONSTRAINT people_level_fk, DROP CONSTRAINT people_host_fk, DROP COLUMN type, DROP COLUMN level_id, DROP COLUMN host_person_id, DROP COLUMN joined_on, DROP COLUMN left_on, DROP COLUMN left_reason, DROP COLUMN suspended_at, DROP COLUMN privacy_consent_at, DROP COLUMN anonymized_at, DROP COLUMN notes_enc');
        DB::statement('DROP TABLE app.member_levels');
    }
};
