<?php

declare(strict_types=1);

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Console\AuditHeadsCopyCommand;
use App\Modules\Audit\Console\AuditIndexUsageCommand;
use App\Modules\Audit\Console\AuditPartitionsCommand;
use App\Modules\Audit\Console\AuditPruneCommand;
use App\Modules\Audit\Console\SecurityLogPruneCommand;
use App\Modules\Audit\Console\VerifyAuditCommand;
use App\Modules\Audit\Contracts\ActingMember;
use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Audit\Contracts\SecurityLog;
use App\Modules\Audit\Export\AuditDataExport;
use App\Modules\Audit\Listeners\RecordSecurityEvents;
use App\Modules\Audit\Listeners\RecordSensitiveReads;
use App\Modules\Audit\Log\ChainedAuditLog;
use App\Modules\Audit\Log\DatabaseSecurityLog;
use App\Modules\Audit\Log\HeadCopies;
use App\Modules\Audit\Log\PendingEntries;
use App\Modules\Cifratura\Contracts\SensitiveFieldRead;
use App\Modules\Core\Contracts\DataExport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([AuditDataExport::class], DataExport::TAG);
        // Una sola istanza per il registro e per gli eventi delle transazioni, che legano le righe raccolte alla conferma.
        $this->app->singleton(PendingEntries::class);
        $this->app->singleton(ChainedAuditLog::class);
        $this->app->singleton(AuditLog::class, static fn (Application $app): ChainedAuditLog => $app->make(ChainedAuditLog::class));
        // Il socio che agisce vale per la richiesta o il lavoro in corso: mai per il successivo nello stesso processo.
        $this->app->scoped(ActingMember::class);
        $this->app->singleton(HeadCopies::class);
        $this->app->singleton(SecurityLog::class, DatabaseSecurityLog::class);
        $this->app->scoped(RecordSensitiveReads::class);
    }

    public function boot(): void
    {
        Event::subscribe(RecordSecurityEvents::class);
        Event::listen(SensitiveFieldRead::class, [RecordSensitiveReads::class, 'handle']);

        // Righe del registro scritte dentro una transazione: seguono i punti di salvataggio e si legano alla catena
        // subito prima della conferma (ChainedAuditLog, PendingEntries).
        $app = $this->app;
        Event::listen(TransactionBeginning::class, static function (TransactionBeginning $e) use ($app): void {
            $app->make(PendingEntries::class)->began($e->connection);
        });
        Event::listen(TransactionRolledBack::class, static function (TransactionRolledBack $e) use ($app): void {
            $app->make(PendingEntries::class)->rolledBack($e->connection);
        });
        Event::listen(TransactionCommitting::class, static function (TransactionCommitting $e) use ($app): void {
            $app->make(ChainedAuditLog::class)->beforeCommit($e->connection);
        });
        Event::listen(TransactionCommitted::class, static function (TransactionCommitted $e) use ($app): void {
            $app->make(ChainedAuditLog::class)->afterCommit($e->connection);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAuditCommand::class, AuditPartitionsCommand::class, AuditPruneCommand::class, AuditHeadsCopyCommand::class, SecurityLogPruneCommand::class, AuditIndexUsageCommand::class]);

            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('saas:audit:partitions')->monthlyOn(1, '02:13')->withoutOverlapping();
                // Tiene l'anno corrente e il precedente: parte solo con l'interruttore acceso (saas.audit_prune_enabled).
                $schedule->command('saas:audit:prune')->monthlyOn(1, '02:43')->withoutOverlapping()->when(static fn (): bool => config('saas.audit_prune_enabled') === true);
                // Copia delle teste e verifica del registro girano ogni notte dentro `saas:night` (Core), in sequenza con
                // gli altri controlli: dal punto di controllo, e un settimo delle associazioni per intero (--rotate).
                // Registri di sicurezza (accessi operatori e staff): 18 mesi.
                $schedule->command('saas:audit:prune-security')->weeklyOn(1, '03:27')->withoutOverlapping();
            });
        }
    }
}
