<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/** Natura della categoria: decide se vale per le entrate, per le uscite o per entrambe. Dal plugin. */
enum CategoryKind: string
{
    case Membership = 'membership';
    case ActivityFee = 'activity_fee';
    case Donation = 'donation';
    case OtherIncome = 'other_income';
    case MemberReimbursement = 'member_reimbursement';
    case ActivityCost = 'activity_cost';
    case GeneralCost = 'general_cost';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Membership => 'Quota associativa',
            self::ActivityFee => 'Quota attività / corso',
            self::Donation => 'Erogazione liberale',
            self::OtherIncome => 'Altra entrata',
            self::MemberReimbursement => 'Rimborso spese socio/volontario',
            self::ActivityCost => 'Costo attività',
            self::GeneralCost => 'Costo generale',
            self::Adjustment => 'Rettifica di cassa',
        };
    }

    public function isIncome(): bool
    {
        return in_array($this, [self::Membership, self::ActivityFee, self::Donation, self::OtherIncome, self::Adjustment], true);
    }

    public function isExpense(): bool
    {
        return in_array($this, [self::MemberReimbursement, self::ActivityCost, self::GeneralCost, self::Adjustment], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
