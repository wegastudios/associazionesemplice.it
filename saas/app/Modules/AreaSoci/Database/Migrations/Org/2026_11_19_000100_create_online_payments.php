<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pagamenti online dei soci (dal PaymentService del plugin), con i conti Stripe e PayPal dell'associazione: i soldi
 * vanno all'associazione, noi non vediamo le carte. Le chiavi segrete sono cifrate con la chiave dell'associazione.
 * Ogni pagamento ricorda le voci scelte (ricalcolate sul server) e, una volta incassato, le ricevute emesse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.payment_settings (
                org_id              uuid        NOT NULL REFERENCES app.orgs(id),
                id                  uuid        NOT NULL,
                stripe_enabled      boolean     NOT NULL DEFAULT false,
                stripe_secret_enc   bytea,
                stripe_webhook_enc  bytea,
                paypal_enabled      boolean     NOT NULL DEFAULT false,
                paypal_client_id    text        CHECK (length(paypal_client_id) <= 200),
                paypal_secret_enc   bytea,
                paypal_live         boolean     NOT NULL DEFAULT false,
                account_id          uuid,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id),
                UNIQUE (org_id),
                FOREIGN KEY (org_id, account_id) REFERENCES app.accounts(org_id, id)
            )
        SQL);
        DB::statement('CREATE INDEX payment_settings_account ON app.payment_settings (org_id, account_id) WHERE account_id IS NOT NULL');
        OrgSchema::isolate('app.payment_settings');
        OrgSchema::grant('app.payment_settings', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.online_payments (
                org_id       uuid        NOT NULL REFERENCES app.orgs(id),
                id           uuid        NOT NULL,
                person_id    uuid        NOT NULL,
                provider     text        NOT NULL CHECK (provider IN ('stripe', 'paypal')),
                status       text        NOT NULL DEFAULT 'created' CHECK (status IN ('created', 'pending', 'settled', 'review', 'failed', 'cancelled')),
                amount_cents bigint      NOT NULL CHECK (amount_cents BETWEEN 50 AND 100000000),
                paid_cents   bigint      CHECK (paid_cents BETWEEN 0 AND 100000000),
                items        jsonb       NOT NULL CHECK (jsonb_typeof(items) = 'array'),
                receipts     jsonb       NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(receipts) = 'array'),
                provider_ref text        CHECK (length(provider_ref) <= 255),
                error        text        CHECK (length(error) <= 1000),
                created_at   timestamptz NOT NULL DEFAULT now(),
                updated_at   timestamptz NOT NULL DEFAULT now(),
                paid_at      timestamptz,
                reviewed_at  timestamptz,
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, person_id) REFERENCES app.people(org_id, id)
            )
        SQL);
        DB::statement('CREATE INDEX online_payments_person ON app.online_payments (org_id, person_id, created_at)');
        DB::statement('CREATE INDEX online_payments_status ON app.online_payments (org_id, status, created_at)');
        DB::statement('CREATE UNIQUE INDEX online_payments_ref ON app.online_payments (org_id, provider, provider_ref) WHERE provider_ref IS NOT NULL');
        OrgSchema::isolate('app.online_payments');
        OrgSchema::grant('app.online_payments', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.online_payments');
        DB::statement('DROP TABLE app.payment_settings');
    }
};
