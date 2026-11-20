<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Donazioni dell'associazione (dal modulo Donazioni del plugin): l'importo proposto, la spunta «Aggiungi X € per una
 * donazione» al pagamento e l'invito a donare nei quattro posti. Tutto spento di default.
 */
final readonly class OrgDonations
{
    public const AMOUNT = 'donations.amount_cents';

    public const CHECKOUT = 'donations.checkout';

    public const DEFAULT_AMOUNT = 1000;

    public const MIN_CENTS = 100;

    public const MAX_CENTS = 1_000_000;

    public function __construct(private OrgSettings $settings) {}

    /** L'importo che l'associazione propone (modificabile da chi paga). */
    public function amountCents(): int
    {
        $v = $this->settings->get(self::AMOUNT);

        return is_int($v) && $v >= self::MIN_CENTS && $v <= self::MAX_CENTS ? $v : self::DEFAULT_AMOUNT;
    }

    /** La spunta «Aggiungi X € per una donazione» compare al pagamento online? */
    public function checkout(): bool
    {
        return $this->settings->get(self::CHECKOUT) === true;
    }
}
