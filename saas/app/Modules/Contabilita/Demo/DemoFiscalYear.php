<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Demo;

use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\DemoSeeder;

/** Per primo: l'anno fiscale della demo parte a settembre, e gli anni già creati si rifanno da quel mese. */
final readonly class DemoFiscalYear implements DemoSeeder
{
    public function __construct(private FiscalYearService $years) {}

    public function order(): int
    {
        return 1;
    }

    public function seed(string $orgId): void
    {
        $this->years->chooseStartMonth(DemoScale::START_MONTH);
    }
}
