<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Catalogo;

/**
 * Regole di calcolo dei prezzi del catalogo (CONTRATTO_PORTALE.md §2.1). Importi in centesimi interi, IVA esclusa:
 * nessun arrotondamento tranne quello dell'IVA, calcolata una sola volta sul totale. Il portale applica le stesse
 * regole (casi comuni in tests/Fixtures/catalogo-casi.json).
 */
final class PriceCalculator
{
    /**
     * Scaglioni marginali sui soci: fino a `base_members` si paga `base_cents`; ogni socio oltre si paga al prezzo della
     * fascia in cui cade. `up_to` è il numero TOTALE di soci in cui la fascia finisce, non la sua ampiezza.
     * Importo del periodo = importo mensile × `months_billed`.
     *
     * @param  array<string, mixed>  $price
     */
    public static function members(array $price, int $members): int
    {
        $baseMembers = self::int($price['base_members'] ?? null);
        $monthly = self::int($price['base_cents'] ?? null);
        $from = $baseMembers;
        $tiers = is_array($price['tiers'] ?? null) ? $price['tiers'] : [];
        foreach ($tiers as $tier) {
            if (! is_array($tier) || $members <= $from) {
                break;
            }
            $upTo = $tier['up_to'] ?? null;
            $top = is_int($upTo) ? min($members, $upTo) : $members;
            $monthly += ($top - $from) * self::int($tier['unit_cents'] ?? null);
            $from = $top;
        }

        return $monthly * self::int($price['months_billed'] ?? 1);
    }

    /**
     * Scatto per fascia: l'importo della prima fascia che contiene la quantità. Oltre l'ultima fascia chiusa il
     * prezzo non è a listino (su preventivo): null.
     *
     * @param  array<string, mixed>  $price
     */
    public static function steps(array $price, int $quantity): ?int
    {
        $steps = is_array($price['steps'] ?? null) ? $price['steps'] : [];
        foreach ($steps as $step) {
            if (is_array($step) && (! isset($step['up_to']) || (is_int($step['up_to']) && $quantity <= $step['up_to']))) {
                return self::int($step['amount_cents'] ?? null);
            }
        }

        return null;
    }

    /** IVA di un imponibile, al centesimo più vicino (metà in su). */
    public static function vat(int $netCents, int $percent): int
    {
        return intdiv($netCents * $percent * 2 + 100, 200);
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
