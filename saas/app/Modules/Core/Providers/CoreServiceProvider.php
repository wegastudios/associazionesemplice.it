<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Access\EmployeeCounter;
use App\Modules\Core\Console\CreateOrgCommand;
use App\Modules\Core\Console\DemoResetCommand;
use App\Modules\Core\Console\HealthCommand;
use App\Modules\Core\Console\InviteOperatorCommand;
use App\Modules\Core\Console\MigrateAllCommand;
use App\Modules\Core\Console\PurgeTmpCommand;
use App\Modules\Core\Console\ResetUserTwoFactorCommand;
use App\Modules\Core\Console\SelfTestPurgeCommand;
use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Contracts\OrgPurger;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Contracts\PlatformSettingsStore;
use App\Modules\Core\Contracts\StaffCount;
use App\Modules\Core\Database\DatabaseRegistry;
use App\Modules\Core\Export\CoreDataExport;
use App\Modules\Core\Http\Middleware\ResolveProxyClient;
use App\Modules\Core\Org\DatabaseOrgContext;
use App\Modules\Core\Org\DatabaseOrgDirectory;
use App\Modules\Core\Org\DatabaseOrgPurger;
use App\Modules\Core\Platform\PlatformConfig;
use App\Modules\Core\Platform\PlatformSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([CoreDataExport::class], DataExport::TAG);
        $this->app->singleton(DatabaseRegistry::class);
        $this->app->singleton(DatabaseNames::class, DatabaseRegistry::class);
        $this->app->singleton(OrgDirectory::class, DatabaseOrgDirectory::class);
        $this->app->singleton(OrgPurger::class, DatabaseOrgPurger::class);
        $this->app->singleton(PlatformSettingsStore::class, PlatformSettings::class);
        // Comandi e code (cron, lavoratore delle code) hanno per tutto il processo i tempi massimi delle operazioni lunghe.
        $this->app->singleton(OrgContext::class, fn (): DatabaseOrgContext => new DatabaseOrgContext(
            $this->app->make(DatabaseManager::class), $this->app->make(DatabaseRegistry::class),
            long: $this->app->runningInConsole() && ! $this->app->runningUnitTests(),
        ));
        $this->app->scoped(OrgSettings::class);
        $this->app->bind(StaffCount::class, EmployeeCounter::class);
    }

    public function boot(): void
    {
        // Dietro il proxy del portale: con il segreto condiviso (SAAS_PROXY_SECRET) l'indirizzo del visitatore lo legge
        // ResolveProxyClient e nessun indirizzo è «fidato». Senza segreto valgono ancora gli indirizzi di SAAS_TRUSTED_PROXIES
        // (vuoto = nessuno; «*» non è accettato; l'host inoltrato non si legge mai: gli indirizzi sono quelli configurati).
        $secret = config('saas.proxy_secret');
        $proxies = config('saas.trusted_proxies');
        if (! (is_string($secret) && strlen($secret) >= ResolveProxyClient::MIN_LENGTH) && is_string($proxies) && $proxies !== '') {
            $list = array_values(array_filter(array_map('trim', explode(',', $proxies)), static fn (string $p): bool => $p !== '' && $p !== '*' && $p !== '0.0.0.0/0'));
            if ($list !== []) {
                TrustProxies::at($list);
                TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
            }
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
        // Un lavoro «sync» gira dentro la richiesta (o il comando) che lo ha lanciato, con la sua associazione: non si azzera.
        $reset = function (JobProcessing|JobProcessed|JobFailed $event): void {
            if ($event->connectionName === 'sync') {
                return;
            }
            $this->app->make(OrgContext::class)->leave();
        };
        // Query oltre la soglia (config saas.slow_query_ms): una riga nel log con il testo e i tempi, mai i valori (dati dei soci).
        DB::listen(static function (QueryExecuted $query): void {
            $limit = config('saas.slow_query_ms');
            if (is_int($limit) && $limit > 0 && $query->time >= $limit) {
                Log::warning('Query lenta', ['ms' => (int) round($query->time), 'connection' => $query->connectionName, 'sql' => mb_substr($query->sql, 0, 1000)]);
            }
        });

        Event::listen(JobProcessing::class, $reset);
        Event::listen(JobProcessed::class, $reset);
        Event::listen(JobFailed::class, $reset);

        // Ogni messaggio partito consuma la quota del suo mailer, un invio per destinatario: anche gli invii diretti (fuori
        // dalla coda) e i messaggi a più destinatari, così il limite orario dell'account non si supera senza saperlo.
        Event::listen(static function (MessageSent $event): void {
            $mailer = $event->data['mailer'] ?? null;
            MailQueue::sent(count($event->sent->getSymfonySentMessage()->getEnvelope()->getRecipients()), is_string($mailer) && $mailer !== '' ? $mailer : null);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([MigrateAllCommand::class, CreateOrgCommand::class, InviteOperatorCommand::class, HealthCommand::class, DemoResetCommand::class, SelfTestPurgeCommand::class, PurgeTmpCommand::class, ResetUserTwoFactorCommand::class]);

            // Code su Postgres: un lavoratore a giro di cron, che si ferma quando la coda è vuota o dopo 50 secondi
            // (sul server non ci sono processi sempre attivi). Prima la posta urgente, per ultima la massiva.
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('queue:work --queue='.MailQueue::WORKER_QUEUES.' --stop-when-empty --max-time=50 --tries=3')
                    ->everyMinute()->withoutOverlapping(5)->runInBackground();
                // Codici di accesso e link di conferma: un lavoratore a parte, ogni minuto, così non aspettano un lavoro
                // lungo (la copia di sicurezza completa) che tiene occupato quello principale.
                $schedule->command('queue:work --queue='.MailQueue::URGENT.' --stop-when-empty --max-time=55 --tries=3')
                    ->everyMinute()->withoutOverlapping(5)->runInBackground();
                $schedule->command('queue:prune-failed --hours=168')->dailyAt('05:47');
                // File di lavoro rimasti da richieste interrotte (copie, archivi, ricevute): via quelli di più di un'ora.
                $schedule->command('saas:tmp:purge --minutes=60')->everyFifteenMinutes()->withoutOverlapping(10);
                if (config('saas.demo.enabled') && ! app()->environment('production')) {
                    $schedule->command('saas:demo:reset')->dailyAt('03:30')->withoutOverlapping(30);
                }
            });
        }
    }
}
