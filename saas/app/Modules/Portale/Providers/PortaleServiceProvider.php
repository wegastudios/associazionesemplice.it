<?php

declare(strict_types=1);

namespace App\Modules\Portale\Providers;

use App\Modules\Portale\Console\ImportPortalDbCommand;
use App\Modules\Portale\Console\PurgeExpiredCommand;
use App\Modules\Portale\Console\SendLifecycleNoticesCommand;
use App\Modules\Portale\Console\SendUsageCommand;
use App\Modules\Portale\Console\SuspendExpiredCommand;
use App\Modules\Portale\Console\VerifyDomainsCommand;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\LifecyclePolicy;
use App\Modules\Portale\Contracts\PortalNotifier;
use App\Modules\Portale\Contracts\StaffAccess;
use App\Modules\Portale\Entitlements\CatalogLifecyclePolicy;
use App\Modules\Portale\Entitlements\DomainDns;
use App\Modules\Portale\Entitlements\EntitlementReader;
use App\Modules\Portale\Entitlements\SystemDns;
use App\Modules\Portale\Http\Middleware\RequireFullAccount;
use App\Modules\Portale\Http\Middleware\RequirePackage;
use App\Modules\Portale\Notifications\PortalNotifications;
use App\Modules\Portale\Staff\PortalStaffAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class PortaleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Una copia per richiesta o per lavoro in coda: i diritti letti non restano in memoria oltre.
        $this->app->scoped(EntitlementReader::class);
        $this->app->scoped(Entitlements::class, static fn (Application $app): EntitlementReader => $app->make(EntitlementReader::class));
        $this->app->scoped(LifecyclePolicy::class, CatalogLifecyclePolicy::class);
        $this->app->singleton(PortalNotifier::class, PortalNotifications::class);
        $this->app->singleton(StaffAccess::class, PortalStaffAccess::class);
        $this->app->singleton(DomainDns::class, SystemDns::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('package', RequirePackage::class);
        $router->aliasMiddleware('exports.allowed', RequireFullAccount::class);

        // Solo il catalogo pubblico (senza firma); le API firmate hanno i due secchi di ThrottlePortalApi.
        RateLimiter::for('portal-api', static fn (Request $request): Limit => Limit::perMinute(120)->by('portal|'.$request->ip()));

        // Con la cache delle rotte il file non si esegue (H18).
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([SendUsageCommand::class, SuspendExpiredCommand::class, PurgeExpiredCommand::class, SendLifecycleNoticesCommand::class, VerifyDomainsCommand::class, ImportPortalDbCommand::class]);

            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('saas:portal:usage')->dailyAt('04:23')->withoutOverlapping();
                $schedule->command('saas:portal:suspend-expired')->dailyAt('00:41')->withoutOverlapping();
                // La cancellazione dei dati scaduti parte solo con l'interruttore acceso (saas.portal.purge_enabled).
                $schedule->command('saas:portal:purge-expired')->dailyAt('01:17')->withoutOverlapping()->when(static fn (): bool => config('saas.portal.purge_enabled') === true);
                // Alle 9: alle 8 manda la sua posta il portale, che conta sullo stesso tetto orario dell'account di posta.
                $schedule->command('saas:portal:lifecycle-notices')->dailyAt('09:10')->withoutOverlapping()->when(static fn (): bool => config('saas.portal.notices_enabled') === true);
                $schedule->command('saas:portal:verify-domains')->hourly()->withoutOverlapping();
            });
        }
    }
}
