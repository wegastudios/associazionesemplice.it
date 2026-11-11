<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Providers;

use App\Modules\Piattaforma\Catalogo\CatalogPlanLimits;
use App\Modules\Piattaforma\Catalogo\DbCatalog;
use App\Modules\Piattaforma\Console\CreateStaffCommand;
use App\Modules\Piattaforma\Console\InviteStaffCommand;
use App\Modules\Piattaforma\Console\ResetStaffTwoFactorCommand;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Piattaforma\Contracts\PlanLimits;
use App\Modules\Piattaforma\Http\Middleware\RequirePlatformStaff;
use App\Modules\Piattaforma\Identity\IssuerStaffAccess;
use App\Modules\Portale\Contracts\StaffAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class PiattaformaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DbCatalog::class);
        $this->app->singleton(Catalog::class, DbCatalog::class);
        $this->app->singleton(PlanLimits::class, CatalogPlanLimits::class);

        // Con l'accesso unico il SaaS è l'emittente dell'identità dello staff: l'ingresso di /saas/admin si controlla qui,
        // non più sul portale (docs/LOGIN_UNICO_E_DATABASE.md). Emittente spento = come prima.
        $this->app->extend(StaffAccess::class, static fn (StaffAccess $portal, Application $app): StaffAccess => config('saas.staff_issuer.enabled') === true ? $app->make(IssuerStaffAccess::class) : $portal);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('platform.staff', RequirePlatformStaff::class);

        // Accesso dello staff: 5 tentativi al minuto per email e indirizzo, 20 per indirizzo; 5 codici al minuto.
        RateLimiter::for('platform-login', static fn (Request $request): array => [
            Limit::perMinute(5)->by(Str::lower($request->string('email')->toString()).'|'.$request->ip()),
            Limit::perMinute(20)->by('platform-ip|'.$request->ip()),
        ]);
        RateLimiter::for('platform-code', static function (Request $request): Limit {
            $id = $request->session()->get('platform.pending');

            return Limit::perMinute(5)->by('platform-code|'.(is_string($id) ? $id : (string) $request->ip()));
        });

        RateLimiter::for('staff-code', static function (Request $request): Limit {
            $id = $request->session()->get('staff.pending');

            return Limit::perMinute(5)->by('staff-code|'.(is_string($id) ? $id : (string) $request->ip()));
        });

        Route::group([], __DIR__.'/../Http/routes.php');
        Route::group([], __DIR__.'/../Http/staff_api_routes.php');
        Route::group([], __DIR__.'/../Http/staff_invite_routes.php');
        Route::group([], __DIR__.'/../Http/staff_login_routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CreateStaffCommand::class, InviteStaffCommand::class, ResetStaffTwoFactorCommand::class]);
        }
    }
}
