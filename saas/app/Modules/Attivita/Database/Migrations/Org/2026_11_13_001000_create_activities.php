<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attività come centri di costo (dal plugin): corsi ed eventi di un anno sociale, con le iscrizioni registrate dalla
 * segreteria. I movimenti della prima nota puntano all'attività con app.transactions.activity_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.activities (
                org_id               uuid        NOT NULL REFERENCES app.orgs(id),
                id                   uuid        NOT NULL,
                name                 text        NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 160),
                kind                 text        NOT NULL CHECK (kind IN ('course', 'event')),
                social_year          text        NOT NULL CHECK (social_year ~ '^\d{4}(/\d{4})?$'),
                billing              text        NOT NULL DEFAULT 'monthly' CHECK (billing IN ('monthly', 'quarterly', 'once')),
                fee_cents            bigint      NOT NULL DEFAULT 0 CHECK (fee_cents BETWEEN 0 AND 100000000),
                guest_fee_cents      bigint      CHECK (guest_fee_cents BETWEEN 0 AND 100000000),
                instructor_person_id uuid,
                weekdays             smallint[]  NOT NULL DEFAULT '{}' CHECK (weekdays <@ ARRAY[1, 2, 3, 4, 5, 6, 7]::smallint[]),
                starts_on            date,
                ends_on              date,
                location             text        CHECK (length(location) <= 190),
                notes                text        CHECK (length(notes) <= 2000),
                archived_at          timestamptz,
                created_at           timestamptz NOT NULL DEFAULT now(),
                updated_at           timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, instructor_person_id) REFERENCES app.people(org_id, id),
                CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)
            )
        SQL);
        DB::statement('CREATE INDEX activities_year ON app.activities (org_id, social_year, name)');
        DB::statement('CREATE INDEX activities_instructor ON app.activities (org_id, instructor_person_id) WHERE instructor_person_id IS NOT NULL');
        OrgSchema::isolate('app.activities');
        OrgSchema::grant('app.activities', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.enrollments (
                org_id      uuid        NOT NULL REFERENCES app.orgs(id),
                id          uuid        NOT NULL,
                activity_id uuid        NOT NULL,
                person_id   uuid        NOT NULL,
                start_month char(7)     NOT NULL CHECK (start_month ~ '^\d{4}-(0[1-9]|1[0-2])$'),
                end_month   char(7)     CHECK (end_month ~ '^\d{4}-(0[1-9]|1[0-2])$'),
                fee_note    text        CHECK (length(fee_note) <= 190),
                created_by  uuid,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id),
                UNIQUE (org_id, activity_id, person_id),
                FOREIGN KEY (org_id, activity_id) REFERENCES app.activities(org_id, id),
                FOREIGN KEY (org_id, person_id)   REFERENCES app.people(org_id, id),
                CHECK (end_month IS NULL OR end_month >= start_month)
            )
        SQL);
        DB::statement('CREATE INDEX enrollments_person ON app.enrollments (org_id, person_id)');
        OrgSchema::isolate('app.enrollments');
        OrgSchema::grant('app.enrollments', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement('ALTER TABLE app.transactions ADD CONSTRAINT transactions_activity_fk FOREIGN KEY (org_id, activity_id) REFERENCES app.activities(org_id, id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.transactions DROP CONSTRAINT transactions_activity_fk');
        DB::statement('DROP TABLE app.enrollments');
        DB::statement('DROP TABLE app.activities');
    }
};
