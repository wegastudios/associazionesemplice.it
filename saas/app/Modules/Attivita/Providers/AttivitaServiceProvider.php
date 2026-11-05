<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Providers;

use App\Modules\Attivita\Contracts\Activities;
use App\Modules\Attivita\Contracts\MemberActivities;
use App\Modules\Attivita\Export\AttivitaDataExport;
use App\Modules\Attivita\Payments\ActivityDues;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\MemberActivitiesService;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\PayableDues;
use Illuminate\Support\ServiceProvider;

final class AttivitaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([AttivitaDataExport::class], DataExport::TAG);
        $this->app->tag([ActivityDues::class], PayableDues::TAG);
        $this->app->bind(Activities::class, ActivityService::class);
        $this->app->bind(MemberActivities::class, MemberActivitiesService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');
    }
}
