<?php

declare(strict_types=1);

namespace App\Modules\Backup\Providers;

use App\Modules\Backup\BackupConfig;
use App\Modules\Backup\Backups;
use App\Modules\Backup\Console\BackupDumpCommand;
use App\Modules\Backup\Console\BackupPruneCommand;
use App\Modules\Backup\Console\BackupRestoreTestCommand;
use App\Modules\Core\Contracts\DatabaseNames;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Backups::class, static fn (Application $app): Backups => new Backups(
            $app->make(DatabaseNames::class),
            $app->make(DatabaseManager::class),
            $app->make(BackupConfig::class),
            Storage::disk('backups'),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([BackupDumpCommand::class, BackupRestoreTestCommand::class, BackupPruneCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ($this->app->environment(['production', 'staging'])) {
                $schedule->command('saas:backup:dump')->hourlyAt(7)->withoutOverlapping(50);
                $schedule->command('saas:backup:prune')->dailyAt('05:11');
            }
            if ($this->app->environment('staging') && $this->app->make(BackupConfig::class)->hasKeyPair()) {
                $schedule->command('saas:backup:restore-test')->weeklyOn(1, '06:17')->withoutOverlapping();
            }
        });
    }
}
