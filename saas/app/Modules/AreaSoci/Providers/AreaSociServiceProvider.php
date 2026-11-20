<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Providers;

use App\Modules\AreaSoci\Home\PaymentsHome;
use App\Modules\AreaSoci\Http\Middleware\AuthenticateMember;
use App\Modules\AreaSoci\Http\Middleware\IdentifyMemberOrg;
use App\Modules\AreaSoci\Services\CurrentMember;
use App\Modules\AreaSoci\Services\MemberDonationInvites;
use App\Modules\AreaSoci\Setup\MemberAreaAddressStep;
use App\Modules\Core\Contracts\DonationInvites;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\SetupStep;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AreaSociServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PaymentsHome::class], HomeWidget::TAG);
        $this->app->tag([MemberAreaAddressStep::class], SetupStep::TAG);
        $this->app->scoped(CurrentMember::class);
        $this->app->bind(DonationInvites::class, MemberDonationInvites::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('member-org', IdentifyMemberOrg::class);
        $router->aliasMiddleware('member', AuthenticateMember::class);
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');

        // Donazioni dalla pagina pubblica: 10 al minuto dallo stesso indirizzo, 100 all'ora per associazione.
        RateLimiter::for('donations', static function (Request $request): array {
            $slug = $request->route('slug');

            return [
                Limit::perMinute(10)->by('donation|'.$request->ip()),
                Limit::perHour(100)->by('donation-org|'.(is_string($slug) ? $slug : '')),
            ];
        });
    }
}
