<?php

declare(strict_types=1);

namespace App\Modules\Soci\Setup;

use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Contracts\SetupStep;
use App\Modules\Soci\Services\MembershipService;

/** Quota associativa (il mese dell'anno sociale è nel passo SocialYearStep). */
final readonly class MembershipStep implements SetupStep
{
    public function __construct(
        private OrgSettings $settings,
    ) {}

    public function key(): string
    {
        return 'tesseramento';
    }

    public function title(): string
    {
        return 'Quota associativa';
    }

    public function order(): int
    {
        return 20;
    }

    public function visible(Operator $operator): bool
    {
        return $operator->can(Ability::ManageSettings);
    }

    public function props(): array
    {
        return [
            'fee' => Money::plain($this->settings->int(MembershipService::DEFAULT_FEE, 0)),
        ];
    }

    public function errors(array $input): array
    {
        $errors = [];
        $fee = is_string($input['fee'] ?? null) ? trim($input['fee']) : '';
        if ($fee !== '' && ((Money::parse($fee) ?? -1) < 0)) {
            $errors[] = 'La quota associativa non è un importo valido.';
        }

        return $errors;
    }

    public function save(array $input): void
    {
        $fee = is_string($input['fee'] ?? null) ? trim($input['fee']) : '';
        $this->settings->set(MembershipService::DEFAULT_FEE, $fee === '' ? 0 : (Money::parse($fee) ?? 0));
    }
}
