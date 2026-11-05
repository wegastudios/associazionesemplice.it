<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Providers;

use App\Modules\AreaSoci\Http\Middleware\AuthenticateMember;
use App\Modules\AreaSoci\Http\Middleware\IdentifyMemberOrg;
use App\Modules\AreaSoci\Services\CurrentMember;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class AreaSociServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentMember::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('member-org', IdentifyMemberOrg::class);
        $router->aliasMiddleware('member', AuthenticateMember::class);
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');
    }
}
