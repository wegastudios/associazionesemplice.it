<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/** Importi sempre in centesimi interi: niente errori di arrotondamento. Portato da Money del plugin. */
final class Money
{
    /** 1234567 → "12.345,67 €" */
    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').self::grouped(abs($cents)).' €';
    }

    /** Senza simbolo né migliaia, per i CSV: 1234,50 */
    public static function plain(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).','.sprintf('%02d', $abs % 100);
    }

    /** Accetta "12,50", "12.50", "1.500", "1.500,00", "€ 12". null se non valido o oltre un miliardo. */
    public static function parse(?string $input): ?int
    {
        if ($input === null) {
            return null;
        }
        $s = trim(str_replace(['€', ' ', "\xC2\xA0"], '', $input));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(',', '.', str_replace('.', '', $s));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s) === 1) {
            $s = str_replace('.', '', $s);
        }
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $s, $m) !== 1 || strlen($m[2]) > 10) {
            return null;
        }

        // Senza passare dai decimali: «0,29» resta 29 centesimi esatti; oltre il secondo decimale si arrotonda.
        $decimals = $m[3] ?? '';
        $cents = (int) $m[2] * 100 + (int) str_pad(substr($decimals, 0, 2), 2, '0');
        if (strlen($decimals) > 2 && (int) $decimals[2] >= 5) {
            $cents++;
        }
        if ($cents > 100_000_000_000) {
            return null;
        }

        return $m[1] === '-' ? -$cents : $cents;
    }

    private static function grouped(int $abs): string
    {
        return number_format(intdiv($abs, 100), 0, ',', '.').','.sprintf('%02d', $abs % 100);
    }
}
