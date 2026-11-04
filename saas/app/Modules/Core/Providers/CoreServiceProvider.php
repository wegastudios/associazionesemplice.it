<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Console\MigrateAllCommand;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Database\DatabaseRegistry;
use App\Modules\Core\Org\DatabaseOrgContext;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseRegistry::class);
        $this->app->singleton(OrgContext::class, DatabaseOrgContext::class);
    }

    public function boot(): void
    {
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
            $this->commands([MigrateAllCommand::class]);
        }
    }
}
