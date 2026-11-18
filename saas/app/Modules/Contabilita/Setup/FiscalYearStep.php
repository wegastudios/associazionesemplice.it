<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Setup;

use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\RequiredSetupStep;
use App\Modules\Soci\Contracts\MembershipRealignment;

/**
 * Mese di inizio dell'anno fiscale: si sceglie subito. Si può cambiare finché nessun anno fiscale è stato chiuso; con
 * movimenti già registrati serve la conferma (passano all'anno fiscale che inizia nel nuovo mese, le date non cambiano).
 * Anche le tessere seguono l'anno fiscale: se il cambio ne sposta o ne sostituisce, si mostra l'anteprima e serve
 * un'altra conferma; dopo il salvataggio le tessere degli anni aperti si riallineano.
 */
final readonly class FiscalYearStep implements RequiredSetupStep
{
    public function __construct(private FiscalYearService $years, private MembershipRealignment $cards) {}

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

        if ((int) $month !== $this->years->startMonth() && ($input['confirm_cards'] ?? false) !== true) {
            $preview = $this->cards->preview((int) $month);
            if ($preview['moved'] + $preview['superseded'] > 0) {
                return ['Conferma cosa succede alle tessere, oppure lascia il mese com\'era.'];
            }
        }

        return [];
    }

    public function save(array $input): void
    {
        $month = is_numeric($input['start_month'] ?? null) ? (int) $input['start_month'] : 1;
        $changes = $this->years->startMonth() !== $month;
        $was = $this->years->chosenStartMonth() !== null;
        $this->years->chooseStartMonth($month);
        if ($changes) {
            $r = $this->cards->apply($month);
            if (! $was && $r['moved'] + $r['superseded'] === 0) {
                return;
            }
            session()->flash('setup_notice', $r['moved'] + $r['superseded'] === 0
                ? 'Mese dell\'anno fiscale cambiato. Nessuna tessera da riallineare.'
                : 'Mese dell\'anno fiscale cambiato. Tessere riallineate: '.$r['moved'].'. Tessere sostituite: '.$r['superseded'].'.');
        }
    }
}
