<?php

declare(strict_types=1);

namespace App\Modules\Soci\Setup;

use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Contracts\SetupStep;
use App\Modules\Soci\Services\MembershipService;

/** Anno sociale e quota associativa. */
final readonly class MembershipStep implements SetupStep
{
    public function __construct(
        private OrgSettings $settings,
        private MembershipService $memberships,
    ) {}

    public function key(): string
    {
        return 'tesseramento';
    }

    public function title(): string
    {
        return 'Anno sociale e quota';
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
            'start_month' => $this->memberships->startMonth(),
            'fee' => Money::plain($this->settings->int(MembershipService::DEFAULT_FEE, 0)),
        ];
    }

    public function errors(array $input): array
    {
        $errors = [];
        $month = $input['start_month'] ?? 1;
        if (! is_numeric($month) || (int) $month < 1 || (int) $month > 12) {
            $errors[] = 'Scegli il mese in cui comincia l\'anno sociale.';
        }
        $fee = is_string($input['fee'] ?? null) ? trim($input['fee']) : '';
        if ($fee !== '' && ((Money::parse($fee) ?? -1) < 0)) {
            $errors[] = 'La quota associativa non è un importo valido.';
        }

        return $errors;
    }

    public function save(array $input): void
    {
        $month = $input['start_month'] ?? 1;
        $this->settings->set(MembershipService::START_MONTH, is_numeric($month) ? (int) $month : 1);
        $fee = is_string($input['fee'] ?? null) ? trim($input['fee']) : '';
        $this->settings->set(MembershipService::DEFAULT_FEE, $fee === '' ? 0 : (Money::parse($fee) ?? 0));
    }
}
