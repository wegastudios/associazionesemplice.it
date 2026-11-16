<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Providers;

use App\Modules\Contabilita\Archive\ArchivedReceipts;
use App\Modules\Contabilita\Archive\ReceiptYearArchive;
use App\Modules\Contabilita\Console\FixReceiptsCommand;
use App\Modules\Contabilita\Contracts\FiscalYearClosed;
use App\Modules\Contabilita\Contracts\FiscalYearReopened;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\MemberReceipts;
use App\Modules\Contabilita\Contracts\ReceiptIssued;
use App\Modules\Contabilita\Demo\DemoFiscalYear;
use App\Modules\Contabilita\Demo\DemoLedger;
use App\Modules\Contabilita\Export\ContabilitaDataExport;
use App\Modules\Contabilita\Export\ContabilitaReadableExport;
use App\Modules\Contabilita\Home\AccountingHome;
use App\Modules\Contabilita\Ledger\LedgerService;
use App\Modules\Contabilita\Ledger\StatementSnapshots;
use App\Modules\Contabilita\Listeners\FixReceiptFile;
use App\Modules\Contabilita\Listeners\SeedAccounting;
use App\Modules\Contabilita\Receipts\MemberReceiptsService;
use App\Modules\Contabilita\Setup\AccountsStep;
use App\Modules\Contabilita\Setup\EntityStep;
use App\Modules\Contabilita\Setup\FiscalYearStep;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\DemoSeeder;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\OrgCreated;
use App\Modules\Core\Contracts\ReadableExport;
use App\Modules\Core\Contracts\SetupStep;
use App\Modules\Core\Contracts\YearArchives;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class ContabilitaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([AccountingHome::class], HomeWidget::TAG);
        $this->app->tag([ContabilitaDataExport::class], DataExport::TAG);
        $this->app->tag([ContabilitaReadableExport::class], ReadableExport::TAG);
        $this->app->tag([DemoFiscalYear::class, DemoLedger::class], DemoSeeder::TAG);
        $this->app->tag([FiscalYearStep::class, EntityStep::class, AccountsStep::class], SetupStep::TAG);
        $this->app->bind(Ledger::class, LedgerService::class);
        $this->app->scoped(ArchivedReceipts::class);
        $this->app->bind(YearArchives::class, ReceiptYearArchive::class);
        $this->app->bind(MemberReceipts::class, MemberReceiptsService::class);
    }

    public function boot(): void
    {
        Event::listen(OrgCreated::class, SeedAccounting::class);
        Event::listen(ReceiptIssued::class, FixReceiptFile::class);
        Event::listen(FiscalYearClosed::class, [StatementSnapshots::class, 'take']);
        Event::listen(FiscalYearReopened::class, [StatementSnapshots::class, 'supersede']);
        if ($this->app->runningInConsole()) {
            $this->commands([FixReceiptsCommand::class]);
            // Le ricevute emesse prima delle ricevute definitive si fissano a lotti, un lotto per associazione a ogni giro.
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('saas:receipts:fix')->hourlyAt(23)->withoutOverlapping(50);
            });
        }
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');
    }
}
