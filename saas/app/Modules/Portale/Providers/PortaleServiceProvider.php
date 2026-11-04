<?php

declare(strict_types=1);

namespace App\Modules\Portale\Providers;

use App\Modules\Portale\Console\SendUsageCommand;
use App\Modules\Portale\Console\SuspendExpiredCommand;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Entitlements\EntitlementReader;
use App\Modules\Portale\Http\Controllers\PortalApiController;
use App\Modules\Portale\Http\Controllers\SubscriptionSsoController;
use App\Modules\Portale\Http\Middleware\RequirePackage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PortaleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Entitlements::class, EntitlementReader::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('package', RequirePackage::class);

        RateLimiter::for('portal-api', static fn (Request $request): Limit => Limit::perMinute(120)->by('portal|'.$request->ip()));

        // API per il portale: niente sessione, niente CSRF, solo token firmati.
        Route::prefix('api/portale/v1')->middleware('throttle:portal-api')->group(function (): void {
            Route::post('/orgs', [PortalApiController::class, 'provision']);
            Route::put('/orgs/{org}/entitlements', [PortalApiController::class, 'entitlements'])->whereUuid('org');
        });

        Route::middleware(['web', 'auth', 'two-factor', 'operator', 'can:org.subscription'])
            ->get('/gestionale/abbonamento', SubscriptionSsoController::class)
            ->name('subscription');

        if ($this->app->runningInConsole()) {
            $this->commands([SendUsageCommand::class, SuspendExpiredCommand::class]);

            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('saas:portal:usage')->dailyAt('04:23')->withoutOverlapping();
                $schedule->command('saas:portal:suspend-expired')->dailyAt('00:41')->withoutOverlapping();
            });
        }
    }
}
