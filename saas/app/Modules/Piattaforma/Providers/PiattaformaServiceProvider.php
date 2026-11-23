<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Providers;

use App\Modules\Piattaforma\Catalogo\CatalogPlanLimits;
use App\Modules\Piattaforma\Catalogo\DbCatalog;
use App\Modules\Piattaforma\Console\AlignCatalogCommand;
use App\Modules\Piattaforma\Console\CreateStaffCommand;
use App\Modules\Piattaforma\Console\ImportPortalCustomersCommand;
use App\Modules\Piattaforma\Console\InviteStaffCommand;
use App\Modules\Piattaforma\Console\NotifyPortalCatalogCommand;
use App\Modules\Piattaforma\Console\PrepareTiersDraftCommand;
use App\Modules\Piattaforma\Console\ResetStaffTwoFactorCommand;
use App\Modules\Piattaforma\Console\StaffSuperAdminCommand;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Piattaforma\Contracts\PlanLimits;
use App\Modules\Piattaforma\Http\Middleware\RequirePlatformStaff;
use App\Modules\Piattaforma\Identity\IssuerStaffAccess;
use App\Modules\Piattaforma\Identity\OperatorEntry;
use App\Modules\Portale\Contracts\StaffAccess;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class PiattaformaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Il catalogo letto vale per la richiesta o il lavoro in corso: una pubblicazione si vede dal successivo.
        $this->app->scoped(DbCatalog::class);
        $this->app->scoped(Catalog::class, DbCatalog::class);
        $this->app->scoped(PlanLimits::class, CatalogPlanLimits::class);

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

        // Con la cache delle rotte i file non si eseguono (H18).
        foreach (['routes', 'staff_api_routes', 'staff_invite_routes', 'operator_sso_routes'] as $file) {
            $this->loadRoutesFrom(dirname(__DIR__)."/Http/{$file}.php");
        }

        // Il legame utente-persona nasce solo quando l'accesso al gestionale è completo (password e secondo livello).
        Event::listen(Login::class, static function (Login $event): void {
            if ($event->guard === 'web') {
                $id = $event->user->getAuthIdentifier();
                resolve(OperatorEntry::class)->completeLink(is_string($id) ? $id : '');
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([CreateStaffCommand::class, InviteStaffCommand::class, ResetStaffTwoFactorCommand::class, ImportPortalCustomersCommand::class, StaffSuperAdminCommand::class, PrepareTiersDraftCommand::class, NotifyPortalCatalogCommand::class, AlignCatalogCommand::class]);
        }
    }
}
