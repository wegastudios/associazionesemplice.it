<?php

declare(strict_types=1);

namespace App\Modules\Portale\Providers;

use App\Modules\Portale\Console\ImportPortalDbCommand;
use App\Modules\Portale\Console\SendUsageCommand;
use App\Modules\Portale\Console\PurgeExpiredCommand;
use App\Modules\Portale\Console\SuspendExpiredCommand;
use App\Modules\Portale\Console\VerifyDomainsCommand;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\PortalNotifier;
use App\Modules\Portale\Contracts\StaffAccess;
use App\Modules\Portale\Entitlements\DomainDns;
use App\Modules\Portale\Entitlements\EntitlementReader;
use App\Modules\Portale\Entitlements\SystemDns;
use App\Modules\Portale\Http\Controllers\AdminApiController;
use App\Modules\Portale\Http\Controllers\CatalogController;
use App\Modules\Portale\Http\Controllers\PortalApiController;
use App\Modules\Portale\Http\Controllers\PortalServicesController;
use App\Modules\Portale\Http\Controllers\SubscriptionSsoController;
use App\Modules\Portale\Http\Middleware\RequirePackage;
use App\Modules\Portale\Notifications\PortalNotifications;
use App\Modules\Portale\Staff\PortalStaffAccess;
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
        $this->app->singleton(PortalNotifier::class, PortalNotifications::class);
        $this->app->singleton(StaffAccess::class, PortalStaffAccess::class);
        $this->app->singleton(DomainDns::class, SystemDns::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('package', RequirePackage::class);

        RateLimiter::for('portal-api', static fn (Request $request): Limit => Limit::perMinute(120)->by('portal|'.$request->ip()));

        // API per il portale: niente sessione, niente CSRF, solo token firmati.
        Route::prefix('api/portale/v1')->middleware('throttle:portal-api')->group(function (): void {
            Route::post('/orgs', [PortalApiController::class, 'provision']);
            Route::put('/orgs/{org}/entitlements', [PortalApiController::class, 'entitlements'])->whereUuid('org');

            // Amministrazione della piattaforma (§2.3): letture e disattivazione utenti.
            Route::post('/admin/orgs', [AdminApiController::class, 'orgs']);
            Route::post('/admin/orgs/{org}', [AdminApiController::class, 'org'])->whereUuid('org');
            Route::post('/admin/orgs/{org}/operators', [AdminApiController::class, 'operators'])->whereUuid('org');
            Route::post('/admin/users/{user}/state', [AdminApiController::class, 'userState'])->whereUuid('user');
        });

        // Abbonamento v2: il SaaS ricava i limiti dal piano (CONTRATTO_PORTALE.md §3.2).
        Route::put('api/portale/v2/orgs/{org}/subscription', [PortalApiController::class, 'subscription'])
            ->whereUuid('org')->middleware('throttle:portal-api');

        // Promozioni, cambio di piano, dominio e stato (§3.3-§3.6).
        Route::prefix('api/portale/v2')->middleware('throttle:portal-api')->group(function (): void {
            Route::post('promo/check', [PortalServicesController::class, 'promoCheck']);
            Route::post('promo/redeem', [PortalServicesController::class, 'promoRedeem']);
            Route::post('orgs/{org}/plan-change/check', [PortalServicesController::class, 'planChangeCheck'])->whereUuid('org');
            Route::post('orgs/{org}/domain/check', [PortalServicesController::class, 'domainCheck'])->whereUuid('org');
            Route::post('orgs/{org}/domain/set', [PortalServicesController::class, 'domainSet'])->whereUuid('org');
            Route::post('orgs/{org}/status', [PortalServicesController::class, 'status'])->whereUuid('org');
        });

        // Catalogo pubblico e firmato: piani, prezzi e promozioni che il portale legge (§2).
        Route::get('api/catalogo/v1', CatalogController::class)->middleware('throttle:portal-api');

        Route::middleware(['web', 'auth', 'two-factor', 'operator', 'can:org.subscription'])
            ->get('/gestionale/abbonamento', SubscriptionSsoController::class)
            ->name('subscription');

        if ($this->app->runningInConsole()) {
            $this->commands([SendUsageCommand::class, SuspendExpiredCommand::class, PurgeExpiredCommand::class, VerifyDomainsCommand::class, ImportPortalDbCommand::class]);

            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('saas:portal:usage')->dailyAt('04:23')->withoutOverlapping();
                $schedule->command('saas:portal:suspend-expired')->dailyAt('00:41')->withoutOverlapping();
                // La cancellazione dei dati scaduti parte solo con l'interruttore acceso (saas.portal.purge_enabled).
                $schedule->command('saas:portal:purge-expired')->dailyAt('01:17')->withoutOverlapping()->when(static fn (): bool => config('saas.portal.purge_enabled') === true);
                $schedule->command('saas:portal:verify-domains')->hourly()->withoutOverlapping();
            });
        }
    }
}
