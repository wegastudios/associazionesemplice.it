<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Rules;

/** La commissione di incasso di un conto (dal plugin, PaymentConfig::fee_cents): quota fissa più percentuale. */
final class CollectionFee
{
    public const MAX_FIXED_CENTS = 2000;

    public const MAX_BP = 2500;

    /** Centesimi di commissione su un incasso: fisso + importo × punti base / 10000, arrotondata al centesimo. */
    public static function cents(int $amountCents, int $fixedCents, int $bp): int
    {
        if ($amountCents <= 0) {
            return 0;
        }

        return max(0, $fixedCents) + intdiv($amountCents * max(0, $bp) + 5000, 10000);
    }

    /** @return list<string> errori di una commissione impostata sul conto */
    public static function validate(int $fixedCents, int $bp): array
    {
        $errors = [];
        if ($fixedCents < 0 || $fixedCents > self::MAX_FIXED_CENTS) {
            $errors[] = 'La quota fissa va da 0 a 20 euro.';
        }
        if ($bp < 0 || $bp > self::MAX_BP) {
            $errors[] = 'La percentuale va da 0 a 25.';
        }

        return $errors;
    }
}
