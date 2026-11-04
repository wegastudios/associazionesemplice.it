<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Providers;

use App\Modules\Cifratura\Console\DestroyOrgKeysCommand;
use App\Modules\Cifratura\Console\GenerateKeysCommand;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\FieldCipher;
use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Cifratura\Contracts\OrgKeys;
use App\Modules\Cifratura\Contracts\Sealer;
use App\Modules\Cifratura\Crypto\HmacSealer;
use App\Modules\Cifratura\Crypto\SodiumFieldCipher;
use App\Modules\Cifratura\Files\SecretStreamFiles;
use App\Modules\Cifratura\Keys\KeyCache;
use App\Modules\Cifratura\Keys\KeyRing;
use App\Modules\Cifratura\Keys\LocalKeyVault;
use App\Modules\Cifratura\Keys\RemoteKeyVault;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class CifraturaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyVault::class, static function (Application $app): KeyVault {
            $config = $app->make('config');

            return match ($config->get('saas.crypto.vault')) {
                'remote' => new RemoteKeyVault(
                    $app->make(Factory::class),
                    self::string($config->get('saas.crypto.service_url'), 'SAAS_KEY_SERVICE_URL'),
                    self::base64($config->get('saas.crypto.signing_key'), 'SAAS_KEY_SERVICE_SIGNING_KEY', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES),
                    self::number($config->get('saas.crypto.timeout', 3), 'SAAS_KEY_SERVICE_TIMEOUT'),
                ),
                'local' => new LocalKeyVault(self::base64($config->get('saas.crypto.master_key'), 'SAAS_MASTER_KEY', 32)),
                default => throw new RuntimeException('SAAS_KEY_VAULT deve essere remote o local.'),
            };
        });

        $this->app->singleton(KeyCache::class, static fn (Application $app): KeyCache => new KeyCache(
            (int) self::number($app->make('config')->get('saas.crypto.cache_ttl', 300), 'saas.crypto.cache_ttl'),
        ));
        $this->app->singleton(KeyRing::class);
        $this->app->singleton(OrgKeys::class, KeyRing::class);
        $this->app->singleton(FieldCipher::class, SodiumFieldCipher::class);
        $this->app->singleton(Sealer::class, HmacSealer::class);
        $this->app->singleton(EncryptedFiles::class, static fn (Application $app): EncryptedFiles => new SecretStreamFiles(
            Storage::disk('org_files'),
            $app->make(KeyRing::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateKeysCommand::class, DestroyOrgKeysCommand::class]);
        }
    }

    private static function string(mixed $value, string $name): string
    {
        return is_string($value) && $value !== '' ? $value : throw new RuntimeException("{$name} non configurata.");
    }

    private static function number(mixed $value, string $name): float
    {
        return is_numeric($value) && $value > 0 ? (float) $value : throw new RuntimeException("{$name} non valida.");
    }

    private static function base64(mixed $value, string $name, int $bytes): string
    {
        $raw = base64_decode(self::string($value, $name), true);

        return $raw !== false && strlen($raw) === $bytes ? $raw : throw new RuntimeException("{$name} non valida.");
    }
}
