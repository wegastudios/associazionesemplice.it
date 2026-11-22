<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Providers;

use App\Modules\Cifratura\Console\DestroyOrgKeysCommand;
use App\Modules\Cifratura\Console\GenerateKeysCommand;
use App\Modules\Cifratura\Console\MonitorKeyServiceCommand;
use App\Modules\Cifratura\Console\PruneFormatBackupsCommand;
use App\Modules\Cifratura\Console\RecoveryCheckCommand;
use App\Modules\Cifratura\Console\RecoveryCreateCommand;
use App\Modules\Cifratura\Console\RetireLocalKeyCommand;
use App\Modules\Cifratura\Console\RewrapKeysCommand;
use App\Modules\Cifratura\Console\UndoUpgradeFormatCommand;
use App\Modules\Cifratura\Console\UpgradeFormatCommand;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\FieldCipher;
use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Cifratura\Contracts\OrgKeys;
use App\Modules\Cifratura\Contracts\Sealer;
use App\Modules\Cifratura\Contracts\StorageQuota;
use App\Modules\Cifratura\Crypto\HmacSealer;
use App\Modules\Cifratura\Crypto\SodiumFieldCipher;
use App\Modules\Cifratura\Files\SecretStreamFiles;
use App\Modules\Cifratura\Files\StorageQuotaService;
use App\Modules\Cifratura\Keys\KeyCache;
use App\Modules\Cifratura\Keys\KeyRing;
use App\Modules\Cifratura\Keys\VaultFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class CifraturaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VaultFactory::class);
        $this->app->singleton(KeyVault::class, static fn (Application $app): KeyVault => $app->make(VaultFactory::class)->configured());
        $this->app->singleton(KeyCache::class, static fn (Application $app): KeyCache => new KeyCache($app->make(VaultFactory::class)->cacheTtl()));
        $this->app->singleton(KeyRing::class);
        $this->app->singleton(OrgKeys::class, KeyRing::class);
        $this->app->singleton(FieldCipher::class, SodiumFieldCipher::class);
        $this->app->singleton(Sealer::class, HmacSealer::class);
        $this->app->singleton(EncryptedFiles::class, static fn (Application $app): EncryptedFiles => new SecretStreamFiles(
            Storage::disk('org_files'),
            $app->make(KeyRing::class),
        ));
        // Tiene i diritti letti: una copia per richiesta o per lavoro in coda.
        $this->app->scoped(StorageQuota::class, StorageQuotaService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateKeysCommand::class,
                DestroyOrgKeysCommand::class,
                RecoveryCreateCommand::class,
                RecoveryCheckCommand::class,
                RewrapKeysCommand::class,
                RetireLocalKeyCommand::class,
                MonitorKeyServiceCommand::class,
                UpgradeFormatCommand::class,
                UndoUpgradeFormatCommand::class,
                PruneFormatBackupsCommand::class,
            ]);

            // Il servizio chiavi si controlla ogni 5 minuti; con la chiave principale locale non c'è nulla da controllare.
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('saas:keys:monitor')->everyFiveMinutes()->withoutOverlapping(10)
                    ->when(static fn (): bool => config('saas.crypto.vault') === 'remote');
                // Copie d'appoggio del passaggio di formato dei campi cifrati: 30 giorni, poi si tolgono.
                $schedule->command('saas:crypt:upgrade-format:prune')->dailyAt('04:07')->withoutOverlapping();
            });
        }
    }
}
