<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Providers;

use App\Modules\AreaSoci\Console\RequeueWebhookEventsCommand;
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
use Illuminate\Console\Scheduling\Schedule;
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
        if ($this->app->runningInConsole()) {
            $this->commands([RequeueWebhookEventsCommand::class]);
            // Incassi online (H14): gli eventi del webhook rimasti in coda senza lavoro tornano in coda.
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('saas:payments:requeue-webhooks')->everyFiveMinutes()->withoutOverlapping(10);
            });
        }

        // Azioni dei soci collegati, ogni 10 minuti: per socio (dal cookie di sessione, in impronta) e per indirizzo IP.
        //   member-book: prenota (eventi, risorse) e lista d'attesa; member-cancel: annulla; member-profile: recapiti;
        //   member-receipt: PDF delle ricevute; member-pay-result: esito dei pagamenti.
        foreach (['member-book' => [20, 60], 'member-cancel' => [20, 60], 'member-profile' => [10, 30], 'member-receipt' => [30, 100], 'member-pay-result' => [60, 200]] as $name => [$perMember, $perIp]) {
            RateLimiter::for($name, static function (Request $request) use ($name, $perMember, $perIp): array {
                $cookie = $request->cookie(AuthenticateMember::COOKIE);

                return [
                    Limit::perMinutes(10, $perMember)->by($name.'|'.(is_string($cookie) && $cookie !== '' ? hash('sha256', $cookie) : 'ip-'.$request->ip())),
                    Limit::perMinutes(10, $perIp)->by($name.'-ip|'.$request->ip()),
                ];
            });
        }

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
