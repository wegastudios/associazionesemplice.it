<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Catalogo;

/**
 * Dal modulo dell'editor al documento del catalogo e ritorno. Gli importi nei moduli sono in euro («15,50»), nel
 * catalogo in centesimi interi. Un valore che non si capisce NON si corregge: resta com'è e lo rifiuta il validatore
 * (CatalogValidator), che è l'unico a decidere cosa è valido.
 */
final class CatalogForm
{
    /** Euro («15», «15,5», «15.50») → centesimi. Vuoto = null; illeggibile = il testo, per farlo rifiutare. */
    public static function cents(mixed $value): int|string|null
    {
        if (! is_string($value)) {
            return null;
        }
        $v = str_replace([' ', '€'], '', trim($value));
        if ($v === '') {
            return null;
        }
        $v = str_replace(',', '.', $v);
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $v) !== 1) {
            return $value;
        }

        return (int) round((float) $v * 100);
    }

    private static function text(mixed $value): string
    {
        return is_int($value) || is_string($value) ? (string) $value : '';
    }

    public static function euros(mixed $cents): string
    {
        return is_int($cents) ? number_format($cents / 100, 2, ',', '') : '';
    }

    /** Numero intero: vuoto = null; illeggibile = il testo, per farlo rifiutare. */
    public static function int(mixed $value): int|string|null
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }

        return preg_match('/^\d{1,9}$/', $v) === 1 ? (int) $v : $v;
    }

    /**
     * Righe di prezzo del modulo → prezzi del catalogo. Una riga senza tipo è una riga vuota e si salta.
     *
     * @return list<array<string, mixed>>
     */
    public static function prices(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! is_string($row['kind'] ?? null) || $row['kind'] === '') {
                continue;
            }
            $price = [
                'period' => $row['period'] ?? '',
                'kind' => $row['kind'],
                'vat_percent' => self::int($row['vat_percent'] ?? null),
            ];
            if ($row['kind'] === 'flat') {
                $price['amount_cents'] = self::cents($row['amount'] ?? null);
            } elseif ($row['kind'] === 'members_tiers') {
                $price['base_members'] = self::int($row['base_members'] ?? null);
                $price['base_cents'] = self::cents($row['base'] ?? null);
                $price['tiers'] = self::tiers($row['tiers'] ?? null, 'unit_cents');
                $months = self::int($row['months_billed'] ?? null);
                if ($months !== null) {
                    $price['months_billed'] = $months;
                }
            } else {
                $price['steps'] = self::tiers($row['steps'] ?? null, 'amount_cents');
            }
            $out[] = $price;
        }

        return $out;
    }

    /**
     * Prezzi del catalogo → righe del modulo (importi in euro, come testo).
     *
     * @param  mixed  $prices
     * @return list<array<string, mixed>>
     */
    public static function priceRows(mixed $prices): array
    {
        $rows = [];
        foreach (is_array($prices) ? $prices : [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $rows[] = [
                'period' => $p['period'] ?? 'month',
                'kind' => $p['kind'] ?? 'flat',
                'vat_percent' => self::text($p['vat_percent'] ?? 22),
                'amount' => self::euros($p['amount_cents'] ?? null),
                'base_members' => self::text($p['base_members'] ?? null),
                'base' => self::euros($p['base_cents'] ?? null),
                'months_billed' => self::text($p['months_billed'] ?? null),
                'tiers' => self::tierRows($p['tiers'] ?? null, 'unit_cents'),
                'steps' => self::tierRows($p['steps'] ?? null, 'amount_cents'),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    private static function tiers(mixed $rows, string $amountKey): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $upTo = self::int($row['up_to'] ?? null);
            $amount = self::cents($row['amount'] ?? null);
            if ($upTo === null && $amount === null) {
                continue;
            }
            $out[] = ['up_to' => $upTo, $amountKey => $amount];
        }

        return $out;
    }

    /**
     * @return list<array{up_to: string, amount: string}>
     */
    private static function tierRows(mixed $tiers, string $amountKey): array
    {
        $rows = [];
        foreach (is_array($tiers) ? $tiers : [] as $t) {
            if (is_array($t)) {
                $rows[] = ['up_to' => self::text($t['up_to'] ?? null), 'amount' => self::euros($t[$amountKey] ?? null)];
            }
        }

        return $rows;
    }
}
