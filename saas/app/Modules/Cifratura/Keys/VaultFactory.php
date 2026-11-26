<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Cifratura\Contracts\OrgBatch;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use RuntimeException;

/**
 * Costruisce i due custodi della chiave principale dalla configurazione: quello in uso (SAAS_KEY_VAULT) e, per i
 * comandi di passaggio, anche l'altro. Legge la configurazione a ogni chiamata, mai una copia.
 */
final readonly class VaultFactory
{
    public function __construct(
        private Repository $config,
        private Factory $http,
        private KeyServiceBreaker $breaker,
        private OrgBatch $batch,
    ) {}

    public function configured(): KeyVault
    {
        return match ($this->config->get('saas.crypto.vault')) {
            'remote' => $this->remote(),
            'local' => $this->local(),
            default => throw new RuntimeException('SAAS_KEY_VAULT deve essere remote o local.'),
        };
    }

    /** Il controllo del servizio (saas:keys:monitor) lo chiama senza interruttore: deve vedere lo stato vero. */
    public function remote(bool $breaker = true): RemoteKeyVault
    {
        return new RemoteKeyVault(
            $this->http,
            self::string($this->config->get('saas.crypto.service_url'), 'SAAS_KEY_SERVICE_URL'),
            $this->signingKey(),
            self::number($this->config->get('saas.crypto.timeout', 3), 'SAAS_KEY_SERVICE_TIMEOUT'),
            $breaker ? $this->breaker : null,
            $this->batch,
        );
    }

    public function local(): LocalKeyVault
    {
        return new LocalKeyVault($this->localMasterKey());
    }

    /** Il custode della chiave locale VECCHIA (SAAS_MASTER_KEY_OLD), solo per il cambio tra due chiavi locali. */
    public function localOld(): LocalKeyVault
    {
        $old = self::base64($this->config->get('saas.crypto.master_key_old'), 'SAAS_MASTER_KEY_OLD', 32);
        if (hash_equals(LocalKeyVault::TEST_MASTER_KEY, base64_encode($old)) && ! in_array($this->config->get('app.env'), ['local', 'testing'], true)) {
            throw new RuntimeException('SAAS_MASTER_KEY_OLD è la chiave di prova: non è quella in uso.');
        }
        if (hash_equals($old, $this->localMasterKey())) {
            throw new RuntimeException('SAAS_MASTER_KEY_OLD è uguale a SAAS_MASTER_KEY: servono due chiavi diverse.');
        }

        return new LocalKeyVault($old);
    }

    /** La chiave principale locale, se configurata e valida (per la copia di recupero). */
    public function localMasterKey(): string
    {
        $raw = self::base64($this->config->get('saas.crypto.master_key'), 'SAAS_MASTER_KEY', 32);
        if (hash_equals(LocalKeyVault::TEST_MASTER_KEY, base64_encode($raw)) && ! in_array($this->config->get('app.env'), ['local', 'testing'], true)) {
            throw new RuntimeException('SAAS_MASTER_KEY è la chiave di prova: generane una con saas:keys:generate.');
        }

        return $raw;
    }

    public function signingKey(): string
    {
        return self::base64($this->config->get('saas.crypto.signing_key'), 'SAAS_KEY_SERVICE_SIGNING_KEY', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
    }

    public function hasLocalMasterKey(): bool
    {
        $value = $this->config->get('saas.crypto.master_key');

        return is_string($value) && $value !== '';
    }

    public function hasRemote(): bool
    {
        $url = $this->config->get('saas.crypto.service_url');
        $key = $this->config->get('saas.crypto.signing_key');

        return is_string($url) && $url !== '' && is_string($key) && $key !== '';
    }

    public function serviceUrl(): string
    {
        return self::string($this->config->get('saas.crypto.service_url'), 'SAAS_KEY_SERVICE_URL');
    }

    public function cacheTtl(): int
    {
        return (int) self::number($this->config->get('saas.crypto.cache_ttl', 300), 'saas.crypto.cache_ttl');
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
