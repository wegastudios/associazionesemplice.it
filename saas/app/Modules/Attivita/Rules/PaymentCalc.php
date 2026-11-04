<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Rules;

use DateTimeImmutable;

/**
 * Situazione dei pagamenti di una persona in un'attività, mese per mese (dal PaymentCalc del plugin).
 *
 * @phpstan-type Month array{month: string, due: int, paid: int, state: string, missing: int}
 * @phpstan-type Summary array{months: list<Month>, total_due: int, total_paid: int, balance: int, regular: bool, unpaid_months: list<Month>, upcoming: array{month: string, date: string, fee: int}|null}
 */
final class PaymentCalc
{
    public const PAID = 'paid';

    public const PARTIAL = 'partial';

    public const UNPAID = 'unpaid';

    /** Versato per un mese non (ancora) dovuto. */
    public const ADVANCE = 'advance';

    /**
     * Prima data del mese che cade in uno dei giorni indicati (1 = lunedì … 7 = domenica); senza giorni, il 1° del mese.
     *
     * @param  list<int>  $weekdays
     */
    public static function firstLesson(string $month, array $weekdays): string
    {
        $first = new DateTimeImmutable($month.'-01');
        $best = null;
        foreach (array_filter($weekdays, static fn (int $d): bool => $d >= 1 && $d <= 7) as $day) {
            $date = $first->modify('+'.(($day - (int) $first->format('N') + 7) % 7).' days')->format('Y-m-d');
            $best = $best === null || $date < $best ? $date : $best;
        }

        return $best ?? $first->format('Y-m-d');
    }

    /**
     * Corso a pagamento unico, o evento: la quota è dovuta per intero dall'iscrizione, anche a rate. Se l'iscrizione
     * è cancellata prima di qualunque pagamento, non è dovuto nulla.
     *
     * @param  array<string, int>  $paidByMonth
     * @return Summary
     */
    public static function computeOnce(int $fee, string $start, ?string $end, array $paidByMonth): array
    {
        $paid = (int) array_sum($paidByMonth);
        $due = ($end !== null && $paid === 0) ? 0 : $fee;
        $rows = [];
        if ($due > 0 || $paid > 0) {
            $state = $paid >= $due ? ($due === 0 ? self::ADVANCE : self::PAID) : ($paid > 0 ? self::PARTIAL : self::UNPAID);
            $rows[] = ['month' => $start, 'due' => $due, 'paid' => $paid, 'state' => $state, 'missing' => max(0, $due - $paid)];
        }

        return self::summary($rows, null);
    }

    /** Mesi interi da $a a $b («AAAA-MM»), negativi se $b viene prima. */
    public static function monthsBetween(string $a, string $b): int
    {
        return ((int) substr($b, 0, 4) - (int) substr($a, 0, 4)) * 12 + ((int) substr($b, 5, 2) - (int) substr($a, 5, 2));
    }

    /**
     * Porta i versamenti dei mesi di ogni periodo (da $start, di $step mesi) sul mese in cui il periodo comincia.
     *
     * @param  array<string, int>  $paidByMonth
     * @return array<string, int>
     */
    public static function groupByPeriod(array $paidByMonth, string $start, int $step): array
    {
        $out = [];
        foreach ($paidByMonth as $m => $cents) {
            $m = (string) $m;
            $d = self::monthsBetween($start, $m);
            if ($d >= 0) {
                $m = (new DateTimeImmutable($start.'-01'))->modify('+'.($d - $d % $step).' months')->format('Y-m');
            }
            $out[$m] = ($out[$m] ?? 0) + $cents;
        }

        return $out;
    }

    /**
     * Mesi dovuti: da $start fino a $end (se cancellato) o al mese corrente, dentro l'anno sociale. Con i giorni delle
     * lezioni, un mese è dovuto dalla sua prima lezione. I pagamenti valgono per il mese di competenza.
     *
     * @param  list<string>  $yearMonths  i mesi dell'anno sociale
     * @param  array<string, int>  $paidByMonth
     * @param  list<int>  $weekdays
     * @return Summary
     */
    public static function compute(int $fee, string $start, ?string $end, string $today, array $yearMonths, array $paidByMonth, array $weekdays = [], ?string $todayDate = null, int $step = 1): array
    {
        $lastDue = $end === null ? $today : min($end, $today);
        $months = [];
        $upcoming = null;
        if ($step > 1) {
            $paidByMonth = self::groupByPeriod($paidByMonth, $start, $step);
        }
        $hasDays = array_filter($weekdays, static fn (int $d): bool => $d >= 1 && $d <= 7) !== [];
        foreach ($yearMonths as $m) {
            $inRange = $m >= $start && $m <= $lastDue && ($step <= 1 || self::monthsBetween($start, $m) % $step === 0);
            $from = self::firstLesson($m, $weekdays);
            $reached = $todayDate === null || ! $hasDays || $m < $today || $from <= $todayDate;
            if ($inRange && ! $reached && $upcoming === null) {
                $upcoming = ['month' => $m, 'date' => $from, 'fee' => $fee];
            }
            $due = ($inRange && $reached) ? $fee : 0;
            $paid = $paidByMonth[$m] ?? 0;
            if ($due === 0 && $paid === 0) {
                continue;
            }
            $state = $due === 0 ? self::ADVANCE : ($paid >= $due ? self::PAID : ($paid > 0 ? self::PARTIAL : self::UNPAID));
            $months[] = ['month' => $m, 'due' => $due, 'paid' => $paid, 'state' => $state, 'missing' => max(0, $due - $paid)];
        }

        return self::summary($months, $upcoming);
    }

    /**
     * @param  list<Month>  $months
     * @param  array{month: string, date: string, fee: int}|null  $upcoming
     * @return Summary
     */
    private static function summary(array $months, ?array $upcoming): array
    {
        $due = (int) array_sum(array_column($months, 'due'));
        $paid = (int) array_sum(array_column($months, 'paid'));

        return [
            'months' => $months,
            'total_due' => $due,
            'total_paid' => $paid,
            'balance' => $paid - $due, // > 0 credito, < 0 da versare
            'regular' => $paid >= $due,
            'unpaid_months' => array_values(array_filter($months, static fn (array $x): bool => in_array($x['state'], [self::UNPAID, self::PARTIAL], true))),
            'upcoming' => $upcoming,
        ];
    }
}
