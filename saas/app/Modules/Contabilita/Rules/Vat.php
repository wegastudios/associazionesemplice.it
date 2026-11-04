<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Rules;

/**
 * Partita IVA e calcolo dell'IVA (livello minimo: il commercialista completa i quadri). Portato da Fiscal del plugin.
 * Importi in centesimi. L'aliquota null vuol dire «fuori campo IVA»; lo 0% è un'aliquota (esente).
 */
final class Vat
{
    /** @var list<int> */
    public const RATES = [22, 10, 5, 4, 0];

    public static function normalizeNumber(string $value): string
    {
        $value = strtoupper((string) preg_replace('/[\s.\-]+/', '', $value));

        return str_starts_with($value, 'IT') ? substr($value, 2) : $value;
    }

    public static function isValidNumber(string $value): bool
    {
        $value = self::normalizeNumber($value);
        if (preg_match('/^\d{11}$/', $value) !== 1 || $value === '00000000000') {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $d = (int) $value[$i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return (10 - $sum % 10) % 10 === (int) $value[10];
    }

    public static function isRate(mixed $rate): bool
    {
        return is_numeric($rate) && (int) $rate >= 0 && (int) $rate <= 30;
    }

    /** Aliquota scelta in un modulo: vuota o «none» = fuori campo (null), altrimenti un numero da 0 a 30. */
    public static function cleanRate(mixed $raw): ?int
    {
        if ($raw === null || $raw === 'none' || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        return is_numeric($raw) && self::isRate($raw) ? (int) $raw : null;
    }

    /**
     * Scompone un importo: IVA compresa ($includesVat) o esclusa.
     *
     * @return array{net: int, vat: int, gross: int}
     */
    public static function split(int $amountCents, int $rate, bool $includesVat): array
    {
        $rate = max(0, $rate);
        if ($includesVat) {
            $net = (int) round($amountCents * 100 / (100 + $rate));

            return ['net' => $net, 'vat' => $amountCents - $net, 'gross' => $amountCents];
        }
        $vat = (int) round($amountCents * $rate / 100);

        return ['net' => $amountCents, 'vat' => $vat, 'gross' => $amountCents + $vat];
    }
}
