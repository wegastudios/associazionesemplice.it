<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Come gira il PHP web, visto dalla riga di comando: il SaaS chiede a se stesso, con un token firmato con la propria
 * chiave, cosa vede il processo che serve le pagine (la riga di comando ha APCu e OPcache propri, che non dicono nulla
 * sul web). Se la risposta non arriva restituisce null: chi chiama avvisa e va avanti, non è un errore.
 */
final readonly class WebRuntimeProbe
{
    public const AUDIENCE = 'associazionesemplice-saas-runtime';

    public const TYPE = 'runtime_probe';

    public function __construct(private PortalConfig $config) {}

    /**
     * Il rapporto di questo processo (lo stesso che la rotta restituisce dal PHP web): solo numeri e interruttori.
     *
     * @return array{sapi: string, php: string, apcu: bool, opcache: array{enabled: bool, memory_used: int, memory_free: int, cached_scripts: int, validate_timestamps: bool}}
     */
    public static function report(): array
    {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $status = is_array($status) ? $status : [];
        $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
        $stats = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];

        return [
            'sapi' => PHP_SAPI,
            'php' => PHP_VERSION,
            'apcu' => function_exists('apcu_enabled') && apcu_enabled(),
            'opcache' => [
                'enabled' => ($status['opcache_enabled'] ?? false) === true,
                'memory_used' => self::int($memory['used_memory'] ?? 0),
                'memory_free' => self::int($memory['free_memory'] ?? 0),
                'cached_scripts' => self::int($stats['num_cached_scripts'] ?? 0),
                'validate_timestamps' => filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN),
            ],
        ];
    }

    /** @return array<string, mixed>|null il rapporto del PHP web, o null se non raggiungibile */
    public function fetch(): ?array
    {
        try {
            $now = time();
            $token = EdDsaJwt::sign([
                'iss' => $this->config->saasUrl(),
                'aud' => self::AUDIENCE,
                'typ' => self::TYPE,
                'iat' => $now,
                'exp' => $now + 60,
                'jti' => bin2hex(random_bytes(8)),
            ], $this->config->signingSeed(), 'saas-');

            $response = Http::timeout(5)->withoutRedirecting()->acceptJson()->post($this->config->saasUrl().'/api/portale/v2/runtime', ['token' => $token]);
            $body = $response->successful() ? $response->json() : null;

            return is_array($body) && is_bool($body['apcu'] ?? null) && is_array($body['opcache'] ?? null) ? $body : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
