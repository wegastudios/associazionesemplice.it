<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Setup;

use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\SetupStep;

/** Mese di inizio dell'anno fiscale: si sceglie subito, prima di registrare qualunque movimento. */
final readonly class FiscalYearStep implements SetupStep
{
    public function __construct(private FiscalYearService $years) {}

    public function key(): string
    {
        return 'anno_fiscale';
    }

    public function title(): string
    {
        return 'Anno fiscale';
    }

    public function order(): int
    {
        return 5;
    }

    public function visible(Operator $operator): bool
    {
        return $operator->can(Ability::ManageSettings);
    }

    public function props(): array
    {
        return ['start_month' => $this->years->chosenStartMonth(), 'locked' => $this->years->hasMovements()];
    }

    public function errors(array $input): array
    {
        $month = $input['start_month'] ?? null;
        if (! is_numeric($month) || (int) $month < 1 || (int) $month > 12) {
            return ['Scegli il mese in cui comincia l\'anno fiscale.'];
        }
        if ($this->years->hasMovements() && (int) $month !== $this->years->startMonth()) {
            return ['Il mese di inizio dell\'anno fiscale non si cambia dopo aver registrato movimenti.'];
        }

        return [];
    }

    public function save(array $input): void
    {
        $this->years->chooseStartMonth(is_numeric($input['start_month'] ?? null) ? (int) $input['start_month'] : 1);
    }
}
