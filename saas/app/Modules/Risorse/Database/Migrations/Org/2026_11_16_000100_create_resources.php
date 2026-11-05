<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Risorse e prenotazioni (dal plugin): impianti, sale, posti barca e postazioni che si vendono a ore, a giornata o a
 * stagione. Date e ore sono quelle dell'associazione (timestamp senza fuso). Due prenotazioni attive della stessa
 * risorsa non possono sovrapporsi: lo impedisce il database, anche con due richieste insieme.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            CREATE TABLE app.resources (
                org_id              uuid        NOT NULL REFERENCES app.orgs(id),
                id                  uuid        NOT NULL,
                name                text        NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 160),
                category            text        NOT NULL DEFAULT 'other'
                                                CHECK (category IN ('court', 'room', 'berth', 'umbrella', 'parking', 'locker', 'other')),
                typology            text        NOT NULL CHECK (typology IN ('hourly', 'daily', 'seasonal')),
                description         text        CHECK (length(description) <= 2000),
                open_time           char(5)     CHECK (open_time ~ '^([01]\d|2[0-3]):[0-5]\d$'),
                close_time          char(5)     CHECK (close_time ~ '^([01]\d|2[0-3]):[0-5]\d$'),
                slot_minutes        smallint    NOT NULL DEFAULT 60 CHECK (slot_minutes BETWEEN 5 AND 1440),
                min_slots           smallint    NOT NULL DEFAULT 1 CHECK (min_slots BETWEEN 1 AND 48),
                max_slots           smallint    NOT NULL DEFAULT 1 CHECK (max_slots BETWEEN 1 AND 48),
                advance_days        smallint    NOT NULL DEFAULT 0 CHECK (advance_days BETWEEN 0 AND 730),
                fee_cents           bigint      NOT NULL DEFAULT 0 CHECK (fee_cents BETWEEN 0 AND 100000000),
                nonmember_fee_cents bigint      CHECK (nonmember_fee_cents BETWEEN 0 AND 100000000),
                guests_max          smallint    NOT NULL DEFAULT 0 CHECK (guests_max BETWEEN 0 AND 100),
                guest_fee_cents     bigint      NOT NULL DEFAULT 0 CHECK (guest_fee_cents BETWEEN 0 AND 100000000),
                rates               jsonb       NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(rates) = 'array'),
                services            jsonb       NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(services) = 'array'),
                category_id         uuid,
                archived_at         timestamptz,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, category_id) REFERENCES app.categories(org_id, id),
                CHECK (max_slots >= min_slots),
                CHECK (typology <> 'hourly' OR (open_time IS NOT NULL AND close_time IS NOT NULL AND close_time > open_time))
            )
        SQL);
        DB::statement('CREATE INDEX resources_name ON app.resources (org_id, typology, name)');
        DB::statement('CREATE INDEX resources_category ON app.resources (org_id, category_id) WHERE category_id IS NOT NULL');
        OrgSchema::isolate('app.resources');
        OrgSchema::grant('app.resources', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.reservations (
                org_id        uuid        NOT NULL REFERENCES app.orgs(id),
                id            uuid        NOT NULL,
                resource_id   uuid        NOT NULL,
                kind          text        NOT NULL CHECK (kind IN ('booking', 'block')),
                person_id     uuid,
                holder_name   text        CHECK (length(btrim(holder_name)) BETWEEN 1 AND 160),
                member        boolean     NOT NULL DEFAULT true,
                starts_at     timestamp   NOT NULL,
                ends_at       timestamp,
                units         smallint    NOT NULL DEFAULT 1 CHECK (units BETWEEN 1 AND 366),
                guests        jsonb       NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(guests) = 'array'),
                services      jsonb       NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(services) = 'array'),
                fee_due_cents bigint      NOT NULL DEFAULT 0 CHECK (fee_due_cents BETWEEN 0 AND 100000000),
                status        text        NOT NULL DEFAULT 'booked' CHECK (status IN ('booked', 'cancelled')),
                note          text        CHECK (length(note) <= 255),
                series_id     uuid,
                created_by    uuid,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                cancelled_at  timestamptz,
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, resource_id) REFERENCES app.resources(org_id, id),
                FOREIGN KEY (org_id, person_id)   REFERENCES app.people(org_id, id),
                CHECK (ends_at IS NULL OR ends_at > starts_at),
                CHECK (kind <> 'block' OR (person_id IS NULL AND holder_name IS NULL AND fee_due_cents = 0)),
                CHECK (kind <> 'booking' OR person_id IS NOT NULL OR holder_name IS NOT NULL),
                CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL)),
                EXCLUDE USING gist (org_id WITH =, resource_id WITH =, tsrange(starts_at, ends_at, '[)') WITH &&) WHERE (status = 'booked')
            )
        SQL);
        DB::statement('CREATE INDEX reservations_resource ON app.reservations (org_id, resource_id, starts_at)');
        DB::statement('CREATE INDEX reservations_person ON app.reservations (org_id, person_id, starts_at) WHERE person_id IS NOT NULL');
        DB::statement('CREATE INDEX reservations_series ON app.reservations (org_id, series_id) WHERE series_id IS NOT NULL');
        OrgSchema::isolate('app.reservations');
        OrgSchema::grant('app.reservations', ['SELECT', 'INSERT', 'UPDATE']);

        // Gli incassi in prima nota di una prenotazione: il pagato è la somma dei movimenti non stornati.
        DB::statement(<<<'SQL'
            CREATE TABLE app.reservation_payments (
                org_id         uuid        NOT NULL REFERENCES app.orgs(id),
                transaction_id uuid        NOT NULL,
                reservation_id uuid        NOT NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, transaction_id),
                FOREIGN KEY (org_id, transaction_id) REFERENCES app.transactions(org_id, id),
                FOREIGN KEY (org_id, reservation_id) REFERENCES app.reservations(org_id, id)
            )
        SQL);
        DB::statement('CREATE INDEX reservation_payments_reservation ON app.reservation_payments (org_id, reservation_id)');
        OrgSchema::isolate('app.reservation_payments');
        OrgSchema::grant('app.reservation_payments', ['SELECT', 'INSERT']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.resource_waitlist (
                org_id      uuid        NOT NULL REFERENCES app.orgs(id),
                id          uuid        NOT NULL,
                resource_id uuid        NOT NULL,
                person_id   uuid        NOT NULL,
                status      text        NOT NULL DEFAULT 'waiting' CHECK (status IN ('waiting', 'done', 'left')),
                note        text        CHECK (length(note) <= 255),
                created_at  timestamptz NOT NULL DEFAULT now(),
                resolved_at timestamptz,
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, resource_id) REFERENCES app.resources(org_id, id),
                FOREIGN KEY (org_id, person_id)   REFERENCES app.people(org_id, id)
            )
        SQL);
        DB::statement('CREATE INDEX resource_waitlist_person ON app.resource_waitlist (org_id, person_id)');
        DB::statement("CREATE UNIQUE INDEX resource_waitlist_waiting ON app.resource_waitlist (org_id, resource_id, person_id) WHERE status = 'waiting'");
        OrgSchema::isolate('app.resource_waitlist');
        OrgSchema::grant('app.resource_waitlist', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.resource_waitlist');
        DB::statement('DROP TABLE app.reservation_payments');
        DB::statement('DROP TABLE app.reservations');
        DB::statement('DROP TABLE app.resources');
    }
};
