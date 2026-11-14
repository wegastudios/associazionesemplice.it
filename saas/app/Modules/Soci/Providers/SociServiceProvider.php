<?php

declare(strict_types=1);

namespace App\Modules\Soci\Providers;

use App\Modules\Contabilita\Contracts\FiscalYearClosed;
use App\Modules\Contabilita\Contracts\FiscalYearReopened;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\DemoSeeder;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\PayableDues;
use App\Modules\Core\Contracts\SetupStep;
use App\Modules\Soci\Console\SendRemindersCommand;
use App\Modules\Soci\Contracts\MemberProfiles;
use App\Modules\Soci\Contracts\Members;
use App\Modules\Soci\Contracts\People;
use App\Modules\Soci\Demo\DemoMembers;
use App\Modules\Soci\Export\SociDataExport;
use App\Modules\Soci\Home\SociHome;
use App\Modules\Soci\Payments\MembershipDues;
use App\Modules\Soci\Services\MemberBookSnapshots;
use App\Modules\Soci\Services\MemberProfilesService;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\PeopleDirectory;
use App\Modules\Soci\Setup\ApplicationsStep;
use App\Modules\Soci\Setup\MembershipStep;
use App\Modules\Soci\Setup\SocialYearStep;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class SociServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([SociHome::class], HomeWidget::TAG);
        $this->app->tag([SociDataExport::class], DataExport::TAG);
        $this->app->tag([MembershipDues::class], PayableDues::TAG);
        $this->app->tag([DemoMembers::class], DemoSeeder::TAG);
        $this->app->tag([SocialYearStep::class, MembershipStep::class, ApplicationsStep::class], SetupStep::TAG);
        $this->app->bind(Members::class, MembershipService::class);
        $this->app->bind(People::class, PeopleDirectory::class);
        $this->app->bind(MemberProfiles::class, MemberProfilesService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');

        // Chiusura e riapertura di un esercizio: il libro soci dell'anno si fotografa e si conserva.
        Event::listen(FiscalYearClosed::class, [MemberBookSnapshots::class, 'take']);
        Event::listen(FiscalYearReopened::class, [MemberBookSnapshots::class, 'supersede']);

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
