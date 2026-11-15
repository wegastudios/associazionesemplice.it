<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Reverse charge» sulle spese: l'importo si registra senza IVA esposta (aliquota vuota, IVA 0) e il movimento si
 * riconosce da questo segno. Come si tratta nei conteggi lo decide il commercialista: qui il dato è solo registrabile.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE app.transactions ADD COLUMN vat_reverse_charge boolean NOT NULL DEFAULT false;
            ALTER TABLE app.transactions ADD CONSTRAINT transactions_reverse_charge_check
                CHECK (NOT vat_reverse_charge OR (type = 'expense' AND vat_rate IS NULL AND vat_cents = 0));
        SQL);
    }
};
