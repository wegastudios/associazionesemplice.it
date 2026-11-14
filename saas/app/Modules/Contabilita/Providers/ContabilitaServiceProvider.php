<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Providers;

use App\Modules\Contabilita\Contracts\FiscalYearClosed;
use App\Modules\Contabilita\Contracts\FiscalYearReopened;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\MemberReceipts;
use App\Modules\Contabilita\Export\ContabilitaDataExport;
use App\Modules\Contabilita\Home\AccountingHome;
use App\Modules\Contabilita\Ledger\LedgerService;
use App\Modules\Contabilita\Ledger\StatementSnapshots;
use App\Modules\Contabilita\Listeners\SeedAccounting;
use App\Modules\Contabilita\Receipts\MemberReceiptsService;
use App\Modules\Contabilita\Setup\AccountsStep;
use App\Modules\Contabilita\Setup\EntityStep;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\OrgCreated;
use App\Modules\Core\Contracts\SetupStep;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class ContabilitaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([AccountingHome::class], HomeWidget::TAG);
        $this->app->tag([ContabilitaDataExport::class], DataExport::TAG);
        $this->app->tag([EntityStep::class, AccountsStep::class], SetupStep::TAG);
        $this->app->bind(Ledger::class, LedgerService::class);
        $this->app->bind(MemberReceipts::class, MemberReceiptsService::class);
    }

    public function boot(): void
    {
        Event::listen(OrgCreated::class, SeedAccounting::class);
        Event::listen(FiscalYearClosed::class, [StatementSnapshots::class, 'take']);
        Event::listen(FiscalYearReopened::class, [StatementSnapshots::class, 'supersede']);
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');
    }
}
