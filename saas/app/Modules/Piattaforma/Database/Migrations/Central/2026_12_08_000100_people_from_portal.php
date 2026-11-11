<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un solo elenco di persone (docs/LOGIN_UNICO_E_DATABASE.md, passo 4): i clienti del portale entrano nello stesso elenco
 * dello staff. `portal_customer_id` è il riferimento al cliente del portale (per collegare poi ordini e licenze);
 * `legacy_hash` è l'impronta della password come la conserva il portale (bcrypt su pre-hash sha256), usata una volta
 * sola: al primo accesso giusto la password viene riscritta nel formato del SaaS e `legacy_hash` si svuota.
 * `imported_at` serve a poter annullare l'importazione. Solo aggiunte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE core.staff_people ADD COLUMN portal_customer_id text UNIQUE, ADD COLUMN legacy_hash text, ADD COLUMN imported_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE core.staff_people DROP COLUMN portal_customer_id, DROP COLUMN legacy_hash, DROP COLUMN imported_at');
    }
};
