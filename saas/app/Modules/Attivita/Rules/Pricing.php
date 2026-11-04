<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Rules;

/** Contributi: quanto paga una persona e in che stato è il pagamento (dal Pricing del plugin). */
final class Pricing
{
    public const FREE = 'free';

    public const PAID = 'paid';

    public const PARTIAL = 'partial';

    public const UNPAID = 'unpaid';

    /** Gli ospiti pagano il contributo ospiti se è impostato (anche 0 = gratis per loro), altrimenti quello dei soci. */
    public static function feeFor(int $feeCents, ?int $guestFeeCents, bool $isGuest): int
    {
        if ($isGuest && $guestFeeCents !== null) {
            return max(0, $guestFeeCents);
        }

        return max(0, $feeCents);
    }

    public static function state(int $due, int $paid): string
    {
        if ($due <= 0) {
            return self::FREE;
        }
        if ($paid >= $due) {
            return self::PAID;
        }

        return $paid > 0 ? self::PARTIAL : self::UNPAID;
    }

    public static function remaining(int $due, int $paid): int
    {
        return max(0, $due - $paid);
    }
}
