<?php

declare(strict_types=1);

namespace App\Modules\Soci\Rules;

use DateTimeImmutable;

/** Scadenze con preavviso (certificati medici, affiliazioni): stato a una data. Dal plugin. */
final class ExpiryRules
{
    public const VALID = 'valid';

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    public const NONE = 'none';

    public const DEFAULT_WARN = 30;

    /** Giorni di preavviso: tra 1 e 365, altrimenti 30. */
    public static function warnDays(mixed $value): int
    {
        $n = is_numeric($value) ? (int) $value : 0;

        return $n < 1 || $n > 365 ? self::DEFAULT_WARN : $n;
    }

    public static function daysLeft(string $expiresOn, string $today): int
    {
        return (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($expiresOn))->format('%r%a');
    }

    /** Valido fino al giorno di scadenza compreso; in scadenza negli ultimi $warn giorni; senza data: mancante. */
    public static function status(?string $expiresOn, string $today, int $warn): string
    {
        if ($expiresOn === null || $expiresOn === '') {
            return self::NONE;
        }
        $left = self::daysLeft($expiresOn, $today);
        if ($left < 0) {
            return self::EXPIRED;
        }

        return $left <= $warn ? self::EXPIRING : self::VALID;
    }

    public static function label(string $status): string
    {
        return [self::VALID => 'Valido', self::EXPIRING => 'In scadenza', self::EXPIRED => 'Scaduto', self::NONE => 'Mancante'][$status] ?? $status;
    }

    public static function plusYear(string $ymd): string
    {
        return (new DateTimeImmutable($ymd))->modify('+1 year')->format('Y-m-d');
    }
}
