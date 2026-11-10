<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Rules;

/** Viste del calendario: giorno, settimana (quella di partenza) e mese, con il periodo da mostrare e i salti avanti e indietro. */
final class CalendarView
{
    public const DAY = 'giorno';

    public const WEEK = 'settimana';

    public const MONTH = 'mese';

    public static function normalize(string $view): string
    {
        return in_array($view, [self::DAY, self::WEEK, self::MONTH], true) ? $view : self::WEEK;
    }

    /**
     * @return array{from: string, to: string, previous: string, next: string}
     */
    public static function range(string $view, string $date): array
    {
        $t = (int) strtotime($date);

        return match (self::normalize($view)) {
            self::DAY => ['from' => $date, 'to' => $date, 'previous' => date('Y-m-d', strtotime('-1 day', $t)), 'next' => date('Y-m-d', strtotime('+1 day', $t))],
            self::MONTH => [
                'from' => date('Y-m-01', $t), 'to' => date('Y-m-t', $t),
                'previous' => date('Y-m-01', strtotime('first day of last month', $t)), 'next' => date('Y-m-01', strtotime('first day of next month', $t)),
            ],
            default => ['from' => $date, 'to' => date('Y-m-d', strtotime('+6 days', $t)), 'previous' => date('Y-m-d', strtotime('-7 days', $t)), 'next' => date('Y-m-d', strtotime('+7 days', $t))],
        };
    }
}
