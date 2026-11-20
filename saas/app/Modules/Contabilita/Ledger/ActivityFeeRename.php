<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Ledger;

use App\Modules\Contabilita\Contracts\CategoryKind;
use Illuminate\Support\Facades\DB;

/**
 * La voce delle attività da «Quota attività / corso» a «Contributo attività / corso». Si riconosce per natura
 * (`activity_fee`) e per nome esatto: un nome personalizzato non si tocca. Le voci usate da movimenti di un anno fiscale
 * chiuso restano come sono (la guardia del database non ne permette la modifica e il rendiconto chiuso non cambia).
 */
final class ActivityFeeRename
{
    public const OLD_NAME = 'Quota attività / corso';

    public const NEW_NAME = 'Contributo attività / corso';

    /** @return int le voci rinominate (nell'associazione corrente, o in tutte se la RLS è sospesa) */
    public static function up(): int
    {
        return self::rename(self::OLD_NAME, self::NEW_NAME);
    }

    public static function down(): int
    {
        return self::rename(self::NEW_NAME, self::OLD_NAME);
    }

    private static function rename(string $from, string $to): int
    {
        return DB::affectingStatement(<<<'SQL'
            UPDATE app.categories c SET name = ?, updated_at = now()
            WHERE c.kind = ? AND c.name = ?
              AND NOT EXISTS (
                  SELECT 1 FROM app.transactions t JOIN app.fiscal_years f ON f.org_id = t.org_id AND f.year = t.fiscal_year
                  WHERE t.org_id = c.org_id AND t.category_id = c.id AND f.status <> 'open')
        SQL, [$to, CategoryKind::ActivityFee->value, $from]);
    }
}
