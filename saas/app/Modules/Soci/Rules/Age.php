<?php

declare(strict_types=1);

namespace App\Modules\Soci\Rules;

use DateTimeImmutable;

/** Età e data di nascita. Regole pure, dal plugin. */
final class Age
{
    public const ADULT_AT = 18;

    public static function isValidDate(string $ymd): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $dt !== false && $dt->format('Y-m-d') === $ymd && (int) $dt->format('Y') >= 1900;
    }

    /** 31/12/2010, 31-12-2010, 31.12.2010 o 2010-12-31 → 2010-12-31; null se non è una data valida. */
    public static function parse(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $text, $m) === 1) {
            $out = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $text, $m) === 1) {
            $out = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        } else {
            return null;
        }

        return self::isValidDate($out) ? $out : null;
    }

    public static function years(?string $birth, string $today): ?int
    {
        if ($birth === null || ! self::isValidDate($birth) || ! self::isValidDate($today) || $birth > $today) {
            return null;
        }

        return (new DateTimeImmutable($birth))->diff(new DateTimeImmutable($today))->y;
    }

    /** Minorenne a quella data? Senza data di nascita non si sa: false. */
    public static function isMinor(?string $birth, string $today): bool
    {
        $years = self::years($birth, $today);

        return $years !== null && $years < self::ADULT_AT;
    }

    /** Giorno dei 18 anni; chi è nato il 29 febbraio li compie il 1° marzo negli anni non bisestili. */
    public static function adultOn(string $birth): ?string
    {
        return self::isValidDate($birth) ? (new DateTimeImmutable($birth))->modify('+'.self::ADULT_AT.' years')->format('Y-m-d') : null;
    }

    public static function becameAdultWithin(?string $birth, string $today, int $days): bool
    {
        $on = $birth === null ? null : self::adultOn($birth);
        if ($on === null || ! self::isValidDate($today)) {
            return false;
        }
        $from = (new DateTimeImmutable($today))->modify('-'.max(0, $days).' days')->format('Y-m-d');

        return $on <= $today && $on >= $from;
    }

    public static function display(?string $ymd): string
    {
        return $ymd !== null && self::isValidDate($ymd) ? substr($ymd, 8, 2).'/'.substr($ymd, 5, 2).'/'.substr($ymd, 0, 4) : '';
    }
}
