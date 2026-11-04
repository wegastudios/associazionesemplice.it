<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Providers;

use App\Modules\Attivita\Contracts\Activities;
use App\Modules\Attivita\Services\ActivityService;
use Illuminate\Support\ServiceProvider;

final class AttivitaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Activities::class, ActivityService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');
    }
}
