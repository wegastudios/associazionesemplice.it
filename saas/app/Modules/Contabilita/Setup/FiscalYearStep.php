<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Setup;

use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\RequiredSetupStep;

/**
 * Mese di inizio dell'anno fiscale: si sceglie subito. Si può cambiare finché nessun anno fiscale è stato chiuso; con
 * movimenti già registrati serve la conferma (passano all'anno fiscale che inizia nel nuovo mese, le date non cambiano).
 */
final readonly class FiscalYearStep implements RequiredSetupStep
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

    public function satisfied(): bool
    {
        return $this->years->chosenStartMonth() !== null;
    }

    public function visible(Operator $operator): bool
    {
        return $operator->can(Ability::ManageSettings) && $operator->can(Ability::CloseFiscalYear);
    }

    public function props(): array
    {
        return ['start_month' => $this->years->chosenStartMonth(), 'locked' => $this->years->hasClosedYear(), 'has_movements' => $this->years->hasMovements()];
    }

    public function errors(array $input): array
    {
        $month = $input['start_month'] ?? null;
        if (! is_numeric($month) || (int) $month < 1 || (int) $month > 12) {
            return ['Scegli il mese in cui comincia l\'anno fiscale.'];
        }
        $changes = (int) $month !== $this->years->chosenStartMonth();
        if ($changes && $this->years->hasClosedYear()) {
            return ['Hai già chiuso un anno fiscale: il mese di inizio non si può più cambiare.'];
        }
        if ($changes && $this->years->chosenStartMonth() !== null && $this->years->hasMovements() && ($input['confirm_move'] ?? false) !== true) {
            return ['Conferma il cambio: i movimenti già registrati passano all\'anno fiscale che inizia nel nuovo mese.'];
        }

        return [];
    }

    public function save(array $input): void
    {
        $this->years->chooseStartMonth(is_numeric($input['start_month'] ?? null) ? (int) $input['start_month'] : 1);
    }
}
