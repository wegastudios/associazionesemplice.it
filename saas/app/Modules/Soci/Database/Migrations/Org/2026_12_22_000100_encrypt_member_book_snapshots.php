<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\FieldCipher;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La fotografia del libro soci contiene codice fiscale, nascita e residenza: come in `app.people`, si salva cifrata con
 * la chiave dell'associazione (colonna `rows_enc`). Le fotografie già esistenti si cifrano qui e la colonna in chiaro
 * (`rows`) si elimina. Se una riga non si cifra la migrazione si ferma e non cambia nulla (è una sola transazione).
 */
return new class extends Migration
{
    public const FIELD = 'member_book_snapshot.rows';

    public function up(): void
    {
        DB::statement('ALTER TABLE app.member_book_snapshots ADD COLUMN rows_enc bytea');

        // Per leggere le righe di tutte le associazioni la RLS forzata si toglie solo per questa transazione di migrazione.
        DB::statement('ALTER TABLE app.member_book_snapshots NO FORCE ROW LEVEL SECURITY');
        $cipher = app(FieldCipher::class);
        $context = app(OrgContext::class);
        foreach (DB::table('app.member_book_snapshots')->get(['org_id', 'id', 'rows']) as $row) {
            $orgId = is_string($row->org_id ?? null) ? $row->org_id : '';
            $plain = is_string($row->rows ?? null) ? $row->rows : '[]';
            $sealed = $context->run($orgId, fn (): string => $cipher->encrypt($orgId, self::FIELD, $plain));
            DB::table('app.member_book_snapshots')->where('org_id', $orgId)->where('id', $row->id)->update(['rows_enc' => Bytea::encode($sealed)]);
        }
        DB::statement('ALTER TABLE app.member_book_snapshots ALTER COLUMN rows_enc SET NOT NULL');
        DB::statement('ALTER TABLE app.member_book_snapshots DROP COLUMN rows');
        DB::statement('ALTER TABLE app.member_book_snapshots FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        // Non si torna al chiaro: le fotografie restano cifrate.
    }
};
