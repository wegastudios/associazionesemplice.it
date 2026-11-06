<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Providers;

use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\PayableDues;
use App\Modules\Risorse\Contracts\MemberResources;
use App\Modules\Risorse\Export\RisorseDataExport;
use App\Modules\Risorse\Home\ResourcesHome;
use App\Modules\Risorse\Payments\ReservationDues;
use App\Modules\Risorse\Services\MemberResourcesService;
use Illuminate\Support\ServiceProvider;

final class RisorseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ResourcesHome::class], HomeWidget::TAG);
        $this->app->tag([RisorseDataExport::class], DataExport::TAG);
        $this->app->tag([ReservationDues::class], PayableDues::TAG);
        $this->app->bind(MemberResources::class, MemberResourcesService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');
    }
}
