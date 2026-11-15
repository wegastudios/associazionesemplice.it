<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Aspetto fiscale dell'associazione. Il «regime» è solo questo: ha la partita IVA (dato «ente.vat») o no.
 * Senza partita IVA l'IVA non si applica mai: tutto è «IVA esente» e gli importi sono finali.
 * Con la partita IVA le aliquote si scelgono nell'elenco delle impostazioni; lo 0 si chiama «IVA esente».
 */
final readonly class OrgFiscal
{
    public const RATES = 'fiscal.vat_rates';

    public const INCLUDES_VAT = 'fiscal.prices_include_vat';

    public const MEMBERSHIP_RATE = 'fiscal.membership_vat_rate';

    /** Aliquote proposte all'inizio (oltre a «IVA esente»). */
    public const DEFAULT_RATES = [22, 10, 5, 4];

    public function __construct(private OrgSettings $settings) {}

    public function hasVat(): bool
    {
        $v = $this->settings->get('ente.vat');

        return is_string($v) && trim($v) !== '';
    }

    /** @return list<int> le aliquote scelte (oltre a «IVA esente»), dalla più alta; vuoto se non c'è la partita IVA */
    public function rates(): array
    {
        if (! $this->hasVat()) {
            return [];
        }
        $raw = $this->settings->get(self::RATES);

        return is_string($raw) ? self::parse($raw) : self::DEFAULT_RATES;
    }

    /** I prezzi che si inseriscono sono finali (IVA compresa)? Predefinito sì. */
    public function includesVat(): bool
    {
        return $this->settings->get(self::INCLUDES_VAT) !== false;
    }

    /** Aliquota della quota associativa: null senza partita IVA; con la partita IVA 0 («IVA esente») se non scelta. */
    public function membershipRate(): ?int
    {
        if (! $this->hasVat()) {
            return null;
        }
        $v = $this->settings->get(self::MEMBERSHIP_RATE);

        return is_int($v) && ($v === 0 || in_array($v, $this->rates(), true)) ? $v : 0;
    }

    /** L'aliquota si può usare? Vuoto e 0 sì sempre; oltre lo 0 serve la partita IVA e un'aliquota dell'elenco. */
    public function allows(?int $rate): bool
    {
        return $rate === null || $rate === 0 || in_array($rate, $this->rates(), true);
    }

    public static function label(?int $rate): string
    {
        return $rate === null ? 'Fuori campo IVA' : ($rate === 0 ? 'IVA esente' : 'IVA '.$rate.'%');
    }

    /**
     * «22, 10, 5» → [22, 10, 5]: numeri da 1 a 30 senza doppioni, dal più alto.
     *
     * @return list<int>
     */
    public static function parse(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $p) {
            if (preg_match('/^\d{1,2}$/', $p) === 1 && (int) $p >= 1 && (int) $p <= 30) {
                $out[(int) $p] = (int) $p;
            }
        }
        rsort($out);

        return array_values($out);
    }
}
