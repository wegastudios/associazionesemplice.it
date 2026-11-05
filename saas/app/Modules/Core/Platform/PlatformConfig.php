<?php

declare(strict_types=1);

namespace App\Modules\Core\Platform;

use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * All'avvio porta le impostazioni della piattaforma nella configurazione (prendono il posto del file d'ambiente) e
 * applica i ripieghi: cache su database se Redis non c'è; chiave principale locale se il servizio chiavi non è
 * configurato, ma allora la piattaforma è «in prova» e il portale non può creare associazioni vere.
 */
final readonly class PlatformConfig
{
    public function __construct(
        private PlatformSettings $settings,
        private Repository $config,
    ) {}

    /** @return list<string> avvisi sui ripieghi in uso (anche per il controllo di salute) */
    public function apply(): array
    {
        try {
            $s = $this->settings->all();
        } catch (Throwable) {
            $s = []; // database non raggiungibile o non ancora migrato: restano i valori del file d'ambiente
        }
        $c = $this->config;
        $set = static function (string $configKey, ?string $value) use ($c): void {
            if ($value !== null && $value !== '') {
                $c->set($configKey, $value);
            }
        };

        // Posta.
        $set('mail.mailers.smtp.host', $s['mail.host'] ?? null);
        if (isset($s['mail.port'])) {
            $c->set('mail.mailers.smtp.port', (int) $s['mail.port']);
        }
        if (isset($s['mail.encryption'])) {
            $c->set('mail.mailers.smtp.scheme', match ($s['mail.encryption']) {
                'ssl' => 'smtps', 'tls' => 'smtp', default => null,
            });
        }
        $set('mail.mailers.smtp.username', $s['mail.username'] ?? null);
        $set('mail.mailers.smtp.password', $s['mail.password'] ?? null);
        $set('mail.from.address', $s['mail.from_address'] ?? null);
        $set('mail.from.name', $s['mail.from_name'] ?? null);
        if (isset($s['mail.host'])) {
            $c->set('mail.default', 'smtp');
        }

        // Spazio dei backup: se c'è il server SFTP nelle impostazioni, il disco dei backup diventa quello.
        if (isset($s['backup.sftp_host'])) {
            $c->set('filesystems.disks.backups', array_filter([
                'driver' => 'sftp',
                'host' => $s['backup.sftp_host'],
                'port' => (int) ($s['backup.sftp_port'] ?? 22),
                'username' => $s['backup.sftp_username'] ?? null,
                'privateKey' => $s['backup.sftp_private_key'] ?? null,
                'passphrase' => $s['backup.sftp_passphrase'] ?? null,
                'password' => $s['backup.sftp_password'] ?? null,
                'hostFingerprint' => $s['backup.sftp_fingerprint'] ?? null,
                'root' => $s['backup.sftp_root'] ?? '/backups',
                'visibility' => 'private',
                'timeout' => 20,
            ], static fn (mixed $v): bool => $v !== null));
        }

        // Servizio chiavi.
        $set('saas.crypto.service_url', $s['keys.service_url'] ?? null);
        $set('saas.crypto.signing_key', $s['keys.signing_key'] ?? null);

        // Cache.
        $set('database.redis.default.host', $s['cache.redis_host'] ?? null);
        if (isset($s['cache.redis_port'])) {
            $c->set('database.redis.default.port', (int) $s['cache.redis_port']);
        }
        $set('database.redis.default.password', $s['cache.redis_password'] ?? null);
        $store = $s['cache.store'] ?? 'auto';
        if ($store !== 'auto') {
            $c->set('cache.default', $store);
        }

        return $this->fallbacks();
    }

    /** @return list<string> */
    private function fallbacks(): array
    {
        $c = $this->config;
        $warnings = [];

        if ($c->get('cache.default') === 'redis' && ! self::redisAvailable($c->get('database.redis.client'), $c->get('database.redis.default.host'))) {
            $c->set('cache.default', 'database');
            $warnings[] = 'Redis non disponibile: cache sul database.';
        }

        $disk = $c->get('filesystems.disks.backups');
        if (is_array($disk) && ($disk['driver'] ?? null) === 'sftp' && (! is_string($disk['host'] ?? null) || $disk['host'] === '')) {
            $c->set('filesystems.disks.backups', ['driver' => 'local', 'root' => storage_path('app/backups'), 'throw' => true]);
            $warnings[] = 'Spazio dei backup esterno non configurato: i dump restano sul server.';
        }

        if ($c->get('mail.default') === 'smtp' && ! (is_string($c->get('mail.mailers.smtp.host')) && $c->get('mail.mailers.smtp.host') !== '')) {
            $c->set('mail.default', 'log');
            $warnings[] = 'Posta non configurata: le email finiscono nel log.';
        }

        $remoteReady = is_string($c->get('saas.crypto.service_url')) && $c->get('saas.crypto.service_url') !== ''
            && is_string($c->get('saas.crypto.signing_key')) && $c->get('saas.crypto.signing_key') !== '';
        $c->set('saas.crypto.trial', false);
        if ($c->get('saas.crypto.vault') === 'remote' && ! $remoteReady) {
            $c->set('saas.crypto.vault', 'local');
            $c->set('saas.crypto.trial', true);
            $warnings[] = 'Servizio chiavi non configurato: chiave principale locale, solo per associazioni di prova.';
        }

        $c->set('saas.platform_warnings', $warnings);

        return $warnings;
    }

    private static function redisAvailable(mixed $client, mixed $host): bool
    {
        if ($client === 'phpredis' && ! extension_loaded('redis')) {
            return false;
        }
        if (is_string($host) && str_starts_with($host, '/')) {
            return file_exists($host);
        }

        return is_string($host) && $host !== '';
    }
}
