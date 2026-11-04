<?php

declare(strict_types=1);

namespace App\Modules\Soci\Providers;

use App\Modules\Soci\Console\SendRemindersCommand;
use App\Modules\Soci\Contracts\Members;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Domande di adesione: 5 al minuto dallo stesso indirizzo, 50 all'ora per associazione.
        RateLimiter::for('applications', static function (Request $request): array {
            $slug = $request->route('slug');

            return [
                Limit::perMinute(5)->by('application|'.$request->ip()),
                Limit::perHour(50)->by('application-org|'.(is_string($slug) ? $slug : '')),
            ];
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SendRemindersCommand::class]);
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('saas:reminders:send')->dailyAt('08:23')->withoutOverlapping();
            });
        }
    }
}
