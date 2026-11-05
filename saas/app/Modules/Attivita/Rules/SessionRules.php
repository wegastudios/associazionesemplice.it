<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Rules;

use App\Modules\Attivita\Contracts\ActivityError;
use DateTimeImmutable;

/**
 * Date, lezioni e listini, senza database (da Schedule, Attendance e PriceLists del plugin).
 *
 * @phpstan-type PriceList array{key: string, label: string, cents: int, entries: int}
 */
final class SessionRules
{
    public const MAX_DATES = 120;

    public const MAX_LISTS = 8;

    /**
     * I giorni della settimana $weekday (1 = lunedì … 7 = domenica) tra due date comprese.
     *
     * @return list<string>
     */
    public static function weeklyDates(string $from, string $to, int $weekday): array
    {
        $out = [];
        if ($to < $from || $weekday < 1 || $weekday > 7) {
            return $out;
        }
        $d = new DateTimeImmutable($from);
        $d = $d->modify('+'.(($weekday - (int) $d->format('N') + 7) % 7).' days');
        for (; $d->format('Y-m-d') <= $to; $d = $d->modify('+7 days')) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    /**
     * Le lezioni di un corso tra $from e $to: i giorni della settimana del corso, dentro il periodo del corso.
     *
     * @param  list<int>  $weekdays
     * @return list<string> in ordine
     */
    public static function lessonDates(array $weekdays, string $courseFrom, string $courseTo, string $from, string $to): array
    {
        $lo = max($from, $courseFrom);
        $hi = min($to, $courseTo);
        $out = [];
        foreach (array_unique($weekdays) as $w) {
            array_push($out, ...self::weeklyDates($lo, $hi, $w));
        }
        sort($out);

        return $out;
    }

    /**
     * Le date da creare: una sola, oppure ogni settimana da $from a $to (al massimo MAX_DATES).
     *
     * @return list<string>
     */
    public static function newDates(string $from, ?string $to, bool $weekly): array
    {
        self::assertDate($from);
        if (! $weekly || $to === null || $to === '') {
            return [$from];
        }
        self::assertDate($to);
        if ($to < $from) {
            throw new ActivityError('L\'ultima data viene prima della prima.');
        }
        $out = self::weeklyDates($from, $to, (int) (new DateTimeImmutable($from))->format('N'));
        if (count($out) > self::MAX_DATES) {
            throw new ActivityError('Troppe date in una volta (massimo '.self::MAX_DATES.'): accorcia il periodo.');
        }

        return $out;
    }

    /** Orario «H:i» o null; un orario scritto male è un errore. */
    public static function time(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($v)) !== 1) {
            throw new ActivityError('Orario non valido: scrivi ad esempio 18:30.');
        }

        return trim($v);
    }

    /**
     * Listini dal testo del modulo, uno per riga: nome, prezzo e, per un pacchetto, gli ingressi con «x»:
     * «Ingresso singolo 10,00», «Pacchetto 5 ingressi 40 x5».
     *
     * @return list<PriceList>
     */
    public static function parsePriceLists(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(.{1,60}?)\s+(\d+(?:[.,]\d{1,2})?)\s*€?(?:\s+x\s*(\d{1,2}))?$/iu', $line, $m) !== 1) {
                throw new ActivityError('Listini, riga '.($n + 1).': scrivi il nome e il prezzo, e per un pacchetto gli ingressi (es. «Pacchetto 5 ingressi 40 x5»).');
            }
            $entries = isset($m[3]) ? (int) $m[3] : 1;
            if ($entries < 1 || $entries > 50) {
                throw new ActivityError('Listini, riga '.($n + 1).': un pacchetto va da 1 a 50 ingressi.');
            }
            $label = trim($m[1]);
            $key = substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(self::ascii($label))), '_'), 0, 30);
            $key = $key === '' ? 'l'.(count($out) + 1) : $key;
            while (isset($out[$key])) {
                $key .= 'x';
            }
            $out[$key] = ['key' => $key, 'label' => $label, 'cents' => (int) round((float) str_replace(',', '.', $m[2]) * 100), 'entries' => $entries];
        }
        if (count($out) > self::MAX_LISTS) {
            throw new ActivityError('Al massimo '.self::MAX_LISTS.' listini.');
        }

        return array_values($out);
    }

    /** @param  list<PriceList>  $lists */
    public static function priceListsText(array $lists): string
    {
        return implode("\n", array_map(static fn (array $l): string => $l['label'].' '.intdiv($l['cents'], 100).','.sprintf('%02d', $l['cents'] % 100)
            .($l['entries'] > 1 ? ' x'.$l['entries'] : ''), $lists));
    }

    /**
     * @param  list<PriceList>  $lists
     * @return PriceList|null
     */
    public static function findList(array $lists, string $key): ?array
    {
        foreach ($lists as $l) {
            if ($l['key'] === $key) {
                return $l;
            }
        }

        return null;
    }

    /** Posti liberi (null = senza limite). */
    public static function free(?int $capacity, int $taken): ?int
    {
        return $capacity === null ? null : max(0, $capacity - $taken);
    }

    private static function assertDate(string $date): void
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ActivityError('Data non valida.');
        }
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    }
}
