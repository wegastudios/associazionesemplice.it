<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Providers;

use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Ledger\LedgerService;
use App\Modules\Contabilita\Listeners\SeedAccounting;
use App\Modules\Core\Contracts\OrgCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class ContabilitaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Ledger::class, LedgerService::class);
    }

    public function boot(): void
    {
        Event::listen(OrgCreated::class, SeedAccounting::class);
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');
    }
}
