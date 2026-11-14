<?php

declare(strict_types=1);

namespace App\Modules\Soci\Setup;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Contracts\RequiredSetupStep;
use App\Modules\Soci\Services\MembershipService;

/** Mese di inizio dell'anno sociale: si sceglie subito, prima di tesserare chiunque. */
final readonly class SocialYearStep implements RequiredSetupStep
{
    public function __construct(private OrgSettings $settings, private MembershipService $memberships) {}

    public function key(): string
    {
        return 'anno_sociale';
    }

    public function title(): string
    {
        return 'Anno sociale';
    }

    public function order(): int
    {
        return 4;
    }

    public function satisfied(): bool
    {
        return is_numeric($this->settings->get(MembershipService::START_MONTH));
    }

    public function visible(Operator $operator): bool
    {
        return $operator->can(Ability::ManageSettings);
    }

    public function props(): array
    {
        $month = $this->settings->get(MembershipService::START_MONTH);

        return ['start_month' => is_numeric($month) ? (int) $month : null];
    }

    public function errors(array $input): array
    {
        $month = $input['start_month'] ?? null;

        if (! is_numeric($month) || (int) $month < 1 || (int) $month > 12) {
            return ['Scegli il mese in cui comincia l\'anno sociale.'];
        }
        $problem = $this->memberships->startMonthProblem((int) $month);

        return $problem === null ? [] : [$problem];
    }

    public function save(array $input): void
    {
        $this->settings->set(MembershipService::START_MONTH, is_numeric($input['start_month'] ?? null) ? (int) $input['start_month'] : 1);
    }
}
