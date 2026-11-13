<?php

declare(strict_types=1);

namespace App\Modules\Portale;

use App\Modules\Portale\Jwt\EdDsaJwt;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/** Indirizzi, chiavi pubbliche fissate del portale e chiave di firma del SaaS (config/saas.php, sezione portal). */
final readonly class PortalConfig
{
    public const AUDIENCE = 'associazionesemplice-saas';

    public function __construct(private Repository $config) {}

    /** Indirizzo del portale, che è anche l'emittente dei suoi token. */
    public function portalUrl(): string
    {
        return rtrim($this->string('saas.portal.url'), '/');
    }

    /** Emittente dei token del SaaS e destinatario di quelli del portale. */
    public function saasUrl(): string
    {
        return rtrim($this->string('app.url'), '/');
    }

    /** @return array<string, string> kid => chiave pubblica base64url */
    public function portalKeys(): array
    {
        $keys = [];
        $configured = $this->config->get('saas.portal.public_keys', '');
        foreach (explode(',', is_string($configured) ? $configured : '') as $pair) {
            [$kid, $x] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($kid !== '' && strlen(EdDsaJwt::unb64($x)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[$kid] = $x;
            }
        }

        return $keys;
    }

    /** Seme (32 byte) della chiave con cui il SaaS firma verso il portale. */
    public function signingSeed(): string
    {
        $seed = base64_decode($this->string('saas.portal.signing_key'), true);

        return $seed !== false && strlen($seed) === SODIUM_CRYPTO_SIGN_SEEDBYTES
            ? $seed
            : throw new RuntimeException('SAAS_PORTAL_SIGNING_KEY non valida.');
    }

    /** @return list<string> i nameserver a cui il cliente delega il dominio personalizzato */
    public function nameservers(): array
    {
        $list = $this->config->get('saas.domains.nameservers', []);
        $out = [];
        foreach (is_array($list) ? $list : [] as $ns) {
            if (is_string($ns) && $ns !== '') {
                $out[] = rtrim(strtolower($ns), '.');
            }
        }

        return $out;
    }

    public function delegationHours(): int
    {
        $hours = $this->config->get('saas.domains.delegation_hours', 72);

        return is_int($hours) && $hours > 0 ? $hours : 72;
    }

    public function retentionDays(): int
    {
        $days = $this->config->get('saas.portal.retention_days', 180);

        return is_int($days) && $days > 0 ? $days : 180;
    }

    public function trialPurgeDays(): int
    {
        $days = $this->config->get('saas.portal.trial_purge_days', 7);

        return is_int($days) && $days > 0 ? $days : 7;
    }

    public function purgeEnabled(): bool
    {
        return $this->config->get('saas.portal.purge_enabled', false) === true;
    }

    public function purgeMaxPerNight(): int
    {
        $max = $this->config->get('saas.portal.purge_max_per_night', 5);

        return is_int($max) && $max > 0 ? $max : 5;
    }

    public function graceDays(): int
    {
        $days = $this->config->get('saas.portal.grace_days', 0);

        return is_int($days) && $days >= 0 ? $days : 0;
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : throw new RuntimeException("Configurazione mancante: {$key}");
    }
}
