<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Portale\Admin\ReplayedToken;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use Illuminate\Contracts\Cache\Repository as Cache;
use InvalidArgumentException;

/**
 * Verifica dei token delle richieste del portale che non cambiano i diritti (promozioni, cambio di piano, dominio,
 * stato): firma, emittente, destinatario, tipo, vita breve; poi i soli campi previsti. Le richieste che cambiano
 * qualcosa valgono una volta sola (jti).
 */
final readonly class PortalRequest
{
    private const STANDARD = ['iss', 'aud', 'typ', 'iat', 'exp', 'jti'];

    public function __construct(
        private PortalConfig $config,
        private Cache $cache,
        private OrgDirectory $orgs,
    ) {}

    /**
     * @param  list<string>  $fields  i campi ammessi oltre a quelli comuni
     * @return array<string, mixed>
     *
     * @throws ReplayedToken
     */
    public function verify(string $token, string $type, array $fields, bool $once = false): array
    {
        $claims = EdDsaJwt::verify($token, $this->config->portalKeys(), $this->config->portalUrl(), PortalConfig::AUDIENCE, $type, time());

        $unknown = array_diff(array_keys($claims), self::STANDARD, $fields);
        if ($unknown !== []) {
            throw new InvalidArgumentException('campo sconosciuto: '.implode(', ', array_map('strval', $unknown)));
        }
        if ($once) {
            $jti = $claims['jti'] ?? null;
            if (! is_string($jti) || $jti === '' || ! $this->cache->add('portal-jti:'.$jti, 1, 900)) {
                throw new ReplayedToken;
            }
        }

        return $claims;
    }

    /**
     * L'abbonamento dichiarato deve essere quello dell'associazione dell'indirizzo.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws UnknownOrg
     */
    public function assertOrg(array $claims, string $orgId): void
    {
        $ref = $claims['ref'] ?? null;
        if (! is_string($ref) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ref) !== 1) {
            throw new InvalidArgumentException('ref non valido');
        }
        if ($this->orgs->portalRef($orgId) !== $ref) {
            throw new UnknownOrg;
        }
    }

    /** @param  array<string, mixed>  $claims */
    public static function string(array $claims, string $key, string $pattern, bool $nullable = false): ?string
    {
        $value = $claims[$key] ?? null;
        if ($value === null && $nullable) {
            return null;
        }

        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : throw new InvalidArgumentException("{$key} non valido");
    }
}
