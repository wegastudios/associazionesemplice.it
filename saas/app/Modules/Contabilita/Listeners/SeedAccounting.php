<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Listeners;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\AccountType;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Ledger\AccountService;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Core\Contracts\OrgCreated;

/**
 * Dati di partenza di una nuova associazione, come nel plugin: le categorie con i raggruppamenti del rendiconto per
 * cassa, la cassa contanti e l'esercizio dell'anno in corso. Tutto si cambia poi dal gestionale.
 */
final readonly class SeedAccounting
{
    private const GENERAL_INCOME = 'Entrate da attività di interesse generale';

    private const GENERAL_EXPENSE = 'Uscite da attività di interesse generale';

    /** @var list<array{string, CategoryKind, string}> */
    private const CATEGORIES = [
        ['Quota associativa', CategoryKind::Membership, 'Entrate da quote associative'],
        ['Quota attività / corso', CategoryKind::ActivityFee, self::GENERAL_INCOME],
        ['Erogazione liberale', CategoryKind::Donation, 'Erogazioni liberali'],
        ['Altre entrate', CategoryKind::OtherIncome, 'Altre entrate'],
        ['Rimborso spese socio/volontario', CategoryKind::MemberReimbursement, self::GENERAL_EXPENSE],
        ['Costi attività (materiali, noleggi)', CategoryKind::ActivityCost, self::GENERAL_EXPENSE],
        ['Costi generali (affitto, utenze, assicurazione)', CategoryKind::GeneralCost, 'Uscite di supporto generale'],
        ['Rettifica di cassa', CategoryKind::Adjustment, 'Rettifiche'],
    ];

    public function __construct(
        private AccountService $accounts,
        private FiscalYearService $years,
        private AuditLog $audit,
    ) {}

    public function handle(OrgCreated $event): void
    {
        // Una sola riga nel registro per tutta la preparazione, non una per categoria.
        Category::withoutAudit(function (): void {
            foreach (self::CATEGORIES as $i => [$name, $kind, $group]) {
                (new Category(['name' => $name, 'kind' => $kind, 'fiscal_group' => $group, 'sort_order' => $i]))->save();
            }
            $this->accounts->create('Cassa contanti', AccountType::Cash, 0);
        });
        $year = (int) now()->format('Y');
        $this->years->create($year, $year.'-01-01', audit: false);

        $this->audit->record('accounting.seeded', null, null, ['categories' => count(self::CATEGORIES), 'year' => $year]);
    }
}
