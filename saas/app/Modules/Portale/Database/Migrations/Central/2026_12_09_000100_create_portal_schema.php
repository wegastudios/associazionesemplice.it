<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Schema `portal`: i dati durevoli del portale (docs/MIGRAZIONE_DB_PORTALE.md). Il portale vi accede con il ruolo
 * ristretto `portal_app`, che ha diritti solo su questo schema (il ruolo si crea a mano sul server, con la sua
 * password in un file fuori dal repository; se esiste, i diritti si danno qui).
 *
 * Gli id restano quelli del portale (testo): `customer_id` corrisponde a `core.staff_people.portal_customer_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA portal');

        DB::statement(<<<'SQL'
            CREATE TABLE portal.subscriptions (
                id text PRIMARY KEY, customer_id text NOT NULL, plan_code text NOT NULL, period text NOT NULL,
                provider text NOT NULL, provider_ref text, provider_customer text, status text NOT NULL, domain text NOT NULL,
                license_id text, license_key_enc text, expires_at timestamptz, cancel_at_period_end boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL, reminded_for timestamptz, note text, mode text,
                renews text, trial_used_at timestamptz, last_seen_at timestamptz, next_plan text, change_to text,
                domain_changed_at timestamptz, plan_version integer, sites_allowed integer, notice_rev integer
            )
        SQL);
        DB::statement('CREATE INDEX portal_subscriptions_customer_idx ON portal.subscriptions (customer_id)');
        DB::statement('CREATE INDEX portal_subscriptions_license_idx ON portal.subscriptions (license_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE portal.orders (
                id text PRIMARY KEY, customer_id text NOT NULL, product text NOT NULL, plan text NOT NULL, plan_version integer NOT NULL,
                period text NOT NULL, members integer NOT NULL, catalog_rev integer NOT NULL, quote text NOT NULL, status text NOT NULL,
                created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL, org_ref text, provider text, provider_ref text,
                provider_customer text, paid_at timestamptz, cancel_at_period_end boolean NOT NULL DEFAULT false
            )
        SQL);
        DB::statement('CREATE INDEX portal_orders_customer_idx ON portal.orders (customer_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE portal.orgs (
                ref text PRIMARY KEY, customer_id text NOT NULL, org_id text, name text NOT NULL, slug text NOT NULL,
                rev integer NOT NULL DEFAULT 0, created_at timestamptz NOT NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE portal.releases (
                product text NOT NULL, version text NOT NULL, size integer NOT NULL, sha256 text NOT NULL, note text,
                created_at timestamptz NOT NULL, signature text, kid text, PRIMARY KEY (product, version)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE portal.downloads (
                id text PRIMARY KEY, customer_id text NOT NULL, at timestamptz NOT NULL, product text NOT NULL, version text NOT NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE portal.outbox (
                id text PRIMARY KEY, org_id text NOT NULL, rev integer NOT NULL, body text NOT NULL, attempts integer NOT NULL DEFAULT 0,
                next_at timestamptz NOT NULL, done_at timestamptz, last_error text, created_at timestamptz NOT NULL
            )
        SQL);

        DB::statement('CREATE TABLE portal.settings (name text PRIMARY KEY, val text NOT NULL)');

        DB::statement(<<<'SQL'
            CREATE TABLE portal.email_templates (
                event text PRIMARY KEY, subject text NOT NULL, body text NOT NULL, enabled boolean NOT NULL DEFAULT true, updated_at timestamptz NOT NULL
            )
        SQL);

        DB::statement('CREATE TABLE portal.audit (id text PRIMARY KEY, at timestamptz NOT NULL, actor text NOT NULL, action text NOT NULL, detail text)');
        DB::statement('CREATE INDEX portal_audit_at_idx ON portal.audit (at)');

        DB::statement('CREATE TABLE portal.events (provider text NOT NULL, event_id text NOT NULL, processed_at timestamptz NOT NULL, PRIMARY KEY (provider, event_id))');

        // Il ruolo del portale si crea a mano (CREATE ROLE portal_app LOGIN PASSWORD …): senza, nulla da dare.
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'portal_app') THEN
                    GRANT USAGE ON SCHEMA portal TO portal_app;
                    ALTER ROLE portal_app SET timezone = 'UTC';
                    GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA portal TO portal_app;
                END IF;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA portal CASCADE');
    }
};
