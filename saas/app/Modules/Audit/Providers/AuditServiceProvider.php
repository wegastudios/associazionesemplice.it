<?php

declare(strict_types=1);

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Console\AuditPartitionsCommand;
use App\Modules\Audit\Console\AuditPruneCommand;
use App\Modules\Audit\Console\VerifyAuditCommand;
use App\Modules\Audit\Contracts\ActingMember;
use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Audit\Contracts\SecurityLog;
use App\Modules\Audit\Export\AuditDataExport;
use App\Modules\Audit\Listeners\RecordSecurityEvents;
use App\Modules\Audit\Listeners\RecordSensitiveReads;
use App\Modules\Audit\Log\ChainedAuditLog;
use App\Modules\Audit\Log\DatabaseSecurityLog;
use App\Modules\Cifratura\Contracts\SensitiveFieldRead;
use App\Modules\Core\Contracts\DataExport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([AuditDataExport::class], DataExport::TAG);
        $this->app->singleton(AuditLog::class, ChainedAuditLog::class);
        $this->app->singleton(ActingMember::class);
        $this->app->singleton(SecurityLog::class, DatabaseSecurityLog::class);
        $this->app->scoped(RecordSensitiveReads::class);
    }

    public function boot(): void
    {
        Event::subscribe(RecordSecurityEvents::class);
        Event::listen(SensitiveFieldRead::class, [RecordSensitiveReads::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAuditCommand::class, AuditPartitionsCommand::class, AuditPruneCommand::class]);

            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('saas:audit:partitions')->monthlyOn(1, '02:13')->withoutOverlapping();
                // Tiene l'anno corrente e il precedente: parte solo con l'interruttore acceso (saas.audit_prune_enabled).
                $schedule->command('saas:audit:prune')->monthlyOn(1, '02:43')->withoutOverlapping()->when(static fn (): bool => config('saas.audit_prune_enabled') === true);
                $schedule->command('saas:audit:verify')->dailyAt('03:37')->withoutOverlapping();
            });
        }
    }
}
