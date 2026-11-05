<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Rules;

use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Contracts\Typology;
use DateTimeImmutable;

/**
 * Regole delle risorse prenotabili, senza database (dal ResourceRules del plugin): sovrapposizioni, fasce orarie,
 * giornate e stagioni, ospiti, tariffe (a fasce, soci e non soci), servizi aggiuntivi. Date e ore sono testi nel fuso
 * dell'associazione: «Y-m-d H:i:s» (si confrontano come testi) e «H:i».
 *
 * @phpstan-type Band array{days: string, from: string, to: string, fee_cents: int}
 * @phpstan-type Service array{name: string, fee_cents: int}
 */
final class ResourceRules
{
    public const DAYS_ALL = 'all';

    public const DAYS_WEEKDAYS = 'weekdays';

    public const DAYS_WEEKEND = 'weekend';

    public const MAX_DAYS = 31;

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            'court' => 'Campo da gioco', 'room' => 'Sala', 'berth' => 'Posto barca', 'umbrella' => 'Ombrellone o postazione',
            'parking' => 'Posto auto', 'locker' => 'Armadietto', 'other' => 'Altro',
        ];
    }

    /** I due intervalli si sovrappongono? Una fine vuota vale «senza scadenza»; fine di uno = inizio dell'altro no. */
    public static function overlaps(string $aStart, ?string $aEnd, string $bStart, ?string $bEnd): bool
    {
        return ($bEnd === null || $aStart < $bEnd) && ($aEnd === null || $bStart < $aEnd);
    }

    /** Minuti dalla mezzanotte di «H:i» (null se non valido). */
    public static function minutes(string $hhmm): ?int
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m) === 1 ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    public static function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Una richiesta a fasce: controlla e calcola inizio e fine.
     *
     * @param  array<string, mixed>  $res  open_time, close_time, slot_minutes, min_slots, max_slots, advance_days
     * @param  bool  $backdate  la segreteria può prenotare nel passato e oltre l'anticipo
     * @return array{0: string, 1: string}
     */
    public static function slotRange(array $res, string $date, string $start, int $slots, string $now, bool $backdate = false): array
    {
        self::assertDate($date);
        $open = self::minutes(self::str($res['open_time'] ?? ''));
        $close = self::minutes(self::str($res['close_time'] ?? ''));
        $step = self::int($res['slot_minutes'] ?? 0);
        $from = self::minutes($start);
        if ($open === null || $close === null || $step < 5 || $close <= $open) {
            throw new ResourceError('La risorsa non ha orari di apertura validi.');
        }
        if ($from === null) {
            throw new ResourceError('Orario di inizio non valido.');
        }
        $min = max(1, self::int($res['min_slots'] ?? 1));
        $max = max($min, self::int($res['max_slots'] ?? $min));
        if ($slots < $min || $slots > $max) {
            throw new ResourceError($min === $max ? 'Si prenota per '.$min.' '.($min === 1 ? 'fascia' : 'fasce').'.' : 'Si prenotano da '.$min.' a '.$max.' fasce di seguito.');
        }
        if ($from < $open || ($from - $open) % $step !== 0) {
            throw new ResourceError('L\'orario di inizio non corrisponde alle fasce della risorsa.');
        }
        $to = $from + $slots * $step;
        if ($to > $close) {
            throw new ResourceError('La prenotazione supera l\'orario di chiusura ('.self::hhmm($close).').');
        }
        $starts = $date.' '.self::hhmm($from).':00';
        $ends = $date.' '.self::hhmm($to).':00';
        if (! $backdate) {
            self::assertNotPast($starts, $now, 'Questa fascia è già iniziata o passata.');
            self::assertAdvance($res, $date, $now);
        }

        return [$starts, $ends];
    }

    /**
     * Una prenotazione a giornate: dalla mezzanotte del primo giorno alla mezzanotte dopo l'ultimo.
     *
     * @param  array<string, mixed>  $res
     * @return array{0: string, 1: string}
     */
    public static function dayRange(array $res, string $date, int $days, string $now, bool $backdate = false): array
    {
        self::assertDate($date);
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new ResourceError('Si prenota da 1 a '.self::MAX_DAYS.' giorni.');
        }
        $starts = $date.' 00:00:00';
        $ends = (new DateTimeImmutable($date))->modify('+'.$days.' days')->format('Y-m-d').' 00:00:00';
        if (! $backdate) {
            self::assertNotPast($date.' 23:59:59', $now, 'Questo giorno è già passato.');
            self::assertAdvance($res, $date, $now);
        }

        return [$starts, $ends];
    }

    /**
     * Un'assegnazione per una stagione: dal primo giorno all'ultimo compreso; senza ultimo giorno non scade.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function seasonRange(string $from, ?string $to): array
    {
        self::assertDate($from);
        if ($to !== null) {
            self::assertDate($to);
            if ($to < $from) {
                throw new ResourceError('L\'ultimo giorno viene prima del primo.');
            }
        }

        return [$from.' 00:00:00', $to === null ? null : (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00'];
    }

    /**
     * Ospiti: nomi puliti e controllo del massimo ammesso dalla risorsa.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function guests(int $max, array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $n = trim((string) preg_replace('/\s+/u', ' ', strip_tags($n)));
            if ($n !== '') {
                $out[] = mb_substr($n, 0, 120);
            }
        }
        if ($out !== [] && $max <= 0) {
            throw new ResourceError('Questa risorsa non ammette ospiti.');
        }
        if (count($out) > $max) {
            throw new ResourceError('Questa risorsa ammette al massimo '.$max.($max === 1 ? ' ospite' : ' ospiti').'.');
        }

        return $out;
    }

    /**
     * Il dovuto. A ore: ogni fascia alla sua tariffa (a fasce, o la base), oppure la tariffa dei non soci se c'è; a
     * giornata: la tariffa del giorno per i giorni; a stagione: il canone del periodo. Più il supplemento per ospite e i
     * servizi aggiuntivi scelti.
     *
     * @param  array<string, mixed>  $res
     * @param  int  $units  fasce (a ore) o giorni (a giornata); a stagione non conta
     * @param  list<Service>  $services
     */
    public static function fee(array $res, int $units, int $guests, ?string $startsAt, bool $member, array $services = []): int
    {
        $base = max(0, self::int($res['fee_cents'] ?? 0));
        $nonMember = ($res['nonmember_fee_cents'] ?? null) === null ? null : max(0, self::int($res['nonmember_fee_cents']));
        $unit = ! $member && $nonMember !== null ? $nonMember : $base;
        $typology = Typology::tryFrom(self::str($res['typology'] ?? 'hourly')) ?? Typology::Hourly;
        $sum = match ($typology) {
            Typology::Seasonal => $unit,
            Typology::Daily => $unit * max(1, $units),
            Typology::Hourly => ! $member && $nonMember !== null ? $nonMember * max(1, $units)
                : ($startsAt !== null ? self::slotPrice($res, $startsAt, max(1, $units)) : $base * max(1, $units)),
        };
        $sum += max(0, self::int($res['guest_fee_cents'] ?? 0)) * max(0, $guests);
        foreach ($services as $s) {
            $sum += max(0, $s['fee_cents']);
        }

        return $sum;
    }

    /** @return array<string, string> parole del modulo per i giorni delle tariffe */
    public static function rateWords(): array
    {
        return ['tutti' => self::DAYS_ALL, 'feriali' => self::DAYS_WEEKDAYS, 'weekend' => self::DAYS_WEEKEND];
    }

    /**
     * Tariffe a fasce dal testo del modulo, una per riga: «feriali 18:00-22:00 15,00», «weekend 08:00-22:00 20».
     *
     * @return list<Band>
     */
    public static function parseRates(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(tutti|feriali|weekend)\s+((?:[01]\d|2[0-3]):[0-5]\d)\s*-\s*((?:[01]\d|2[0-3]):[0-5]\d)\s+(\d+(?:[.,]\d{1,2})?)\s*€?$/u', $line, $m) !== 1) {
                throw new ResourceError('Tariffe a fasce, riga '.($n + 1).': scrivi ad esempio «feriali 18:00-22:00 15,00» (giorni: tutti, feriali, weekend).');
            }
            if ($m[2] >= $m[3]) {
                throw new ResourceError('Tariffe a fasce, riga '.($n + 1).': l\'ora di fine deve essere dopo quella di inizio.');
            }
            $out[] = ['days' => self::rateWords()[$m[1]], 'from' => $m[2], 'to' => $m[3], 'fee_cents' => (int) round((float) str_replace(',', '.', $m[4]) * 100)];
        }
        if (count($out) > 20) {
            throw new ResourceError('Al massimo 20 tariffe a fasce.');
        }

        return $out;
    }

    /** @param  list<Band>  $bands */
    public static function ratesText(array $bands): string
    {
        $words = array_flip(self::rateWords());

        return implode("\n", array_map(static fn (array $b): string => ($words[$b['days']] ?? 'tutti').' '.$b['from'].'-'.$b['to'].' '.intdiv($b['fee_cents'], 100).','.sprintf('%02d', $b['fee_cents'] % 100), $bands));
    }

    /** @return list<Band> le tariffe a fasce memorizzate, pulite (testo non valido = nessuna) */
    public static function cleanRates(mixed $stored): array
    {
        $list = is_array($stored) ? $stored : (is_string($stored) ? json_decode($stored, true) : null);
        $out = [];
        foreach (is_array($list) ? $list : [] as $b) {
            if (is_array($b) && is_string($b['days'] ?? null) && in_array($b['days'], [self::DAYS_ALL, self::DAYS_WEEKDAYS, self::DAYS_WEEKEND], true)
                && is_string($b['from'] ?? null) && self::minutes($b['from']) !== null && is_string($b['to'] ?? null) && self::minutes($b['to']) !== null) {
                $out[] = ['days' => $b['days'], 'from' => $b['from'], 'to' => $b['to'], 'fee_cents' => max(0, self::int($b['fee_cents'] ?? 0))];
            }
        }

        return $out;
    }

    /**
     * Prezzo di una fascia che comincia a $startsAt: la prima tariffa a fasce che corrisponde, altrimenti la base.
     *
     * @param  array<string, mixed>  $res
     */
    public static function bandFee(array $res, string $startsAt): int
    {
        $d = new DateTimeImmutable($startsAt);
        $dow = (int) $d->format('N');
        $min = (int) self::minutes($d->format('H:i'));
        foreach (self::cleanRates($res['rates'] ?? '') as $b) {
            $dayOk = $b['days'] === self::DAYS_ALL || ($b['days'] === self::DAYS_WEEKDAYS && $dow <= 5) || ($b['days'] === self::DAYS_WEEKEND && $dow >= 6);
            if ($dayOk && $min >= (int) self::minutes($b['from']) && $min < (int) self::minutes($b['to'])) {
                return $b['fee_cents'];
            }
        }

        return max(0, self::int($res['fee_cents'] ?? 0));
    }

    /** @param  array<string, mixed>  $res */
    public static function slotPrice(array $res, string $startsAt, int $slots): int
    {
        $step = max(1, self::int($res['slot_minutes'] ?? 60));
        $start = new DateTimeImmutable($startsAt);
        $sum = 0;
        for ($i = 0; $i < $slots; $i++) {
            $sum += self::bandFee($res, $start->modify('+'.($i * $step).' minutes')->format('Y-m-d H:i:s'));
        }

        return $sum;
    }

    /**
     * Servizi aggiuntivi dal testo del modulo, uno per riga: «Aperitivo 5,00», «Allestimento 30».
     *
     * @return list<Service>
     */
    public static function parseServices(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(.{1,80}?)\s+(\d+(?:[.,]\d{1,2})?)\s*€?$/u', $line, $m) !== 1) {
                throw new ResourceError('Servizi aggiuntivi, riga '.($n + 1).': scrivi il nome e il prezzo, ad esempio «Aperitivo 5,00».');
            }
            $out[] = ['name' => trim($m[1]), 'fee_cents' => (int) round((float) str_replace(',', '.', $m[2]) * 100)];
        }
        if (count($out) > 20) {
            throw new ResourceError('Al massimo 20 servizi aggiuntivi.');
        }

        return $out;
    }

    /** @param  list<Service>  $services */
    public static function servicesText(array $services): string
    {
        return implode("\n", array_map(static fn (array $s): string => $s['name'].' '.intdiv($s['fee_cents'], 100).','.sprintf('%02d', $s['fee_cents'] % 100), $services));
    }

    /**
     * @param  array<string, mixed>  $res
     * @return list<array{start: string, end: string}> le fasce di un giorno
     */
    public static function daySlots(array $res): array
    {
        $open = self::minutes(self::str($res['open_time'] ?? ''));
        $close = self::minutes(self::str($res['close_time'] ?? ''));
        $step = self::int($res['slot_minutes'] ?? 0);
        $out = [];
        if ($open === null || $close === null || $step < 5) {
            return $out;
        }
        for ($t = $open; $t + $step <= $close; $t += $step) {
            $out[] = ['start' => self::hhmm($t), 'end' => self::hhmm($t + $step)];
        }

        return $out;
    }

    /** @return list<string> la prima data e le altre a distanza di una settimana */
    public static function seriesDates(string $first, int $count): array
    {
        $out = [];
        $d = new DateTimeImmutable($first);
        for ($i = 0; $i < max(1, $count); $i++) {
            $out[] = $d->modify('+'.(7 * $i).' days')->format('Y-m-d');
        }

        return $out;
    }

    /** Minuti dell'intervallo A dentro il periodo B (occupazione). Una fine vuota di A vale «senza scadenza». */
    public static function overlapMinutes(string $aStart, ?string $aEnd, string $bStart, string $bEnd): int
    {
        $from = max($aStart, $bStart);
        $to = $aEnd === null ? $bEnd : min($aEnd, $bEnd);

        return $to <= $from ? 0 : (int) round(((int) strtotime($to.' UTC') - (int) strtotime($from.' UTC')) / 60);
    }

    private static function assertDate(string $date): void
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ResourceError('Data non valida.');
        }
    }

    private static function assertNotPast(string $moment, string $now, string $message): void
    {
        if ($moment < $now) {
            throw new ResourceError($message);
        }
    }

    /** @param  array<string, mixed>  $res */
    private static function assertAdvance(array $res, string $date, string $now): void
    {
        $days = self::int($res['advance_days'] ?? 0);
        if ($days > 0 && $date > (new DateTimeImmutable(substr($now, 0, 10)))->modify('+'.$days.' days')->format('Y-m-d')) {
            throw new ResourceError('Si può prenotare al massimo '.$days.' '.($days === 1 ? 'giorno' : 'giorni').' prima.');
        }
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}
