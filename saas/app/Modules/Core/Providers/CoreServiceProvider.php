<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Console\CreateOrgCommand;
use App\Modules\Core\Console\DemoResetCommand;
use App\Modules\Core\Console\HealthCommand;
use App\Modules\Core\Console\InviteOperatorCommand;
use App\Modules\Core\Console\MigrateAllCommand;
use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Contracts\PlatformSettingsStore;
use App\Modules\Core\Database\DatabaseRegistry;
use App\Modules\Core\Export\CoreDataExport;
use App\Modules\Core\Org\DatabaseOrgContext;
use App\Modules\Core\Org\DatabaseOrgDirectory;
use App\Modules\Core\Platform\PlatformConfig;
use App\Modules\Core\Platform\PlatformSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([CoreDataExport::class], DataExport::TAG);
        $this->app->singleton(DatabaseRegistry::class);
        $this->app->singleton(DatabaseNames::class, DatabaseRegistry::class);
        $this->app->singleton(OrgDirectory::class, DatabaseOrgDirectory::class);
        $this->app->singleton(PlatformSettingsStore::class, PlatformSettings::class);
        $this->app->singleton(OrgContext::class, DatabaseOrgContext::class);
    }

    public function boot(): void
    {
        // Dietro il proxy del portale gli X-Forwarded-* valgono solo da questi indirizzi (SAAS_TRUSTED_PROXIES); vuoto = nessuno.
        $proxies = config('saas.trusted_proxies');
        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at(array_values(array_filter(array_map('trim', explode(',', $proxies)), static fn (string $p): bool => $p !== '')));
            TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        }

        // Impostazioni della piattaforma (posta, backup, servizio chiavi, cache) al posto del file d'ambiente, con i ripieghi.
        // Nei test no: ogni applicazione di prova aprirebbe una connessione in più (i test la chiamano da sé).
        if (! $this->app->runningUnitTests()) {
            $this->app->make(PlatformConfig::class)->apply();
        }

        // Email e slug senza distinzione tra maiuscole e minuscole: $table->addColumn('citext', 'email').
        PostgresGrammar::macro('typeCitext', fn (): string => 'citext');

        // Ogni lavoro in coda parte e finisce senza associazione: la imposta il lavoro stesso
        // (RunInOrgContext), così il lavoro precedente non lascia nulla al successivo.
        $reset = function (): void {
            $this->app->make(OrgContext::class)->leave();
        };
        Event::listen(JobProcessing::class, $reset);
        Event::listen(JobProcessed::class, $reset);
        Event::listen(JobFailed::class, $reset);

        if ($this->app->runningInConsole()) {
            $this->commands([MigrateAllCommand::class, CreateOrgCommand::class, InviteOperatorCommand::class, HealthCommand::class, DemoResetCommand::class]);

            // Code su Postgres: un lavoratore a giro di cron, che si ferma quando la coda è vuota o dopo 50 secondi
            // (sul server non ci sono processi sempre attivi).
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('queue:work --stop-when-empty --max-time=50 --tries=3')
                    ->everyMinute()->withoutOverlapping(5)->runInBackground();
                $schedule->command('queue:prune-failed --hours=720')->dailyAt('05:47');
                if (config('saas.demo.enabled') && ! app()->environment('production')) {
                    $schedule->command('saas:demo:reset')->dailyAt('03:30')->withoutOverlapping(30);
                }
            });
        }
    }
}
