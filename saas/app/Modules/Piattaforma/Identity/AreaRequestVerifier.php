<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use App\Modules\Portale\Contracts\StaffAccessDenied;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\Jwt\InvalidToken;
use App\Modules\Portale\PortalConfig;
use Illuminate\Database\DatabaseManager;

/**
 * Verifica le richieste firmate che le aree (WordPress, Supporto) mandano al SaaS: firma con una delle loro chiavi
 * fissate, destinatario e tipo attesi, vita al massimo 60 secondi e identificativo (`jti`) usabile una volta sola.
 */
final readonly class AreaRequestVerifier
{
    public function __construct(private StaffIdentityConfig $config, private DatabaseManager $db) {}

    /**
     * @return array{area: string, claims: array<string, mixed>}
     *
     * @throws StaffAccessDenied
     */
    public function verify(string $token, string $type): array
    {
        $keys = $this->config->areaKeys();
        $kid = $this->kidOf($token);
        if ($kid === null || ! isset($keys[$kid])) {
            throw new StaffAccessDenied('chiave sconosciuta');
        }

        try {
            $claims = EdDsaJwt::verify($token, [$kid => $keys[$kid]['x']], $this->config->requestIssuer(), PortalConfig::AUDIENCE, $type, time());
        } catch (InvalidToken) {
            throw new StaffAccessDenied('token non valido');
        }

        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        $jti = $claims['jti'] ?? null;
        // Una chiave vale per la sua area soltanto: il portale non può parlare a nome del supporto.
        if (($claims['area'] ?? null) !== $keys[$kid]['area']
            || ! is_int($iat) || ! is_int($exp) || $exp - $iat > 60
            || ! is_string($jti) || $jti === '' || strlen($jti) > 90) {
            throw new StaffAccessDenied('contenuto non valido');
        }

        $db = $this->db->connection('pgsql');
        $db->table('core.platform_sso_tokens')->where('expires_at', '<', now())->delete();
        if ($db->table('core.platform_sso_tokens')->insertOrIgnore(['jti' => 'req:'.$jti, 'expires_at' => date('c', $exp + 60)]) === 0) {
            throw new StaffAccessDenied('richiesta già usata');
        }

        return ['area' => $keys[$kid]['area'], 'claims' => $claims];
    }

    private function kidOf(string $token): ?string
    {
        $decoded = json_decode(EdDsaJwt::unb64(explode('.', $token)[0]), true);
        $kid = is_array($decoded) ? ($decoded['kid'] ?? null) : null;

        return is_string($kid) ? $kid : null;
    }
}
