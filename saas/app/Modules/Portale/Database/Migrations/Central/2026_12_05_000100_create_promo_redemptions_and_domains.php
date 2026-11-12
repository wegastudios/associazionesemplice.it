<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promozioni riscattate (una volta sola per ordine del portale) e dominio personalizzato di ogni associazione
 * (CONTRATTO_PORTALE.md §3.3 e §3.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.promo_redemptions (
                order_ref  text        NOT NULL CHECK (order_ref ~ '^[A-Za-z0-9_-]{1,64}$'),
                code       text        NOT NULL,
                org_id     uuid        REFERENCES core.orgs(id),
                uses_left  integer,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (order_ref)
            )
        SQL);
        DB::statement('CREATE INDEX promo_redemptions_code_idx ON core.promo_redemptions (code)');
        OrgSchema::grant('core.promo_redemptions', ['SELECT', 'INSERT']);

        DB::statement(<<<'SQL'
            CREATE TABLE core.org_domains (
                org_id       uuid        NOT NULL REFERENCES core.orgs(id),
                domain       text        NOT NULL UNIQUE,
                state        text        NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'active', 'error')),
                message      text,
                requested_at timestamptz NOT NULL DEFAULT now(),
                checked_at   timestamptz,
                PRIMARY KEY (org_id)
            )
        SQL);
        OrgSchema::grant('core.org_domains', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.org_domains');
        DB::statement('DROP TABLE core.promo_redemptions');
    }
};
