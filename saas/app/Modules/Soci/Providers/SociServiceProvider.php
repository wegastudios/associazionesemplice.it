<?php

declare(strict_types=1);

namespace App\Modules\Soci\Providers;

use App\Modules\Soci\Contracts\Members;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\ServiceProvider;

final class SociServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Members::class, MembershipService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');
    }
}
