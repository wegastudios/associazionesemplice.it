<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use App\Modules\Portale\Contracts\StaffAccess;
use App\Modules\Portale\Contracts\StaffAccessDenied;
use App\Modules\Portale\Contracts\StaffGrant;
use App\Modules\Portale\Contracts\StaffJwt;
use Illuminate\Contracts\Config\Repository;

/**
 * L'ingresso di `/saas/admin` quando il SaaS è esso stesso l'emittente dell'identità dello staff (accesso unico,
 * docs/LOGIN_UNICO_E_DATABASE.md): il token `staff_sso` lo ha firmato questo stesso SaaS con la sua chiave, e la
 * sessione centrale si controlla nell'elenco locale, senza chiamate di rete. Il monouso (`jti`) lo applica chi apre la
 * sessione, come prima.
 */
final readonly class IssuerStaffAccess implements StaffAccess
{
    private const AUDIENCE = 'associazionesemplice-saas';

    public function __construct(private StaffIdentityConfig $identity, private StaffDirectory $directory, private Repository $config) {}

    public function enabled(): bool
    {
        return $this->config->get('saas.staff_sso.enabled') === true;
    }

    public function loginUrl(): string
    {
        $url = $this->config->get('saas.staff_sso.login_url');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        $public = $this->config->get('saas.public_url');

        return rtrim(is_string($public) && $public !== '' ? $public : 'https://associazionesemplice.it', '/').'/accedi';
    }

    public function verify(string $token): StaffGrant
    {
        $now = time();
        $kid = StaffJwt::kidOf($token);
        if ($kid === null) {
            throw new StaffAccessDenied('token non valido');
        }
        $seed = $this->identity->signingSeed();
        if ($seed === '') {
            throw new StaffAccessDenied('chiave non configurata');
        }
        $public = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed))), '+/', '-_'), '=');
        $claims = StaffJwt::verify($token, [$kid => $public], $this->identity->issuer(), self::AUDIENCE, 'staff_sso', $now);

        $email = $claims['sub'] ?? null;
        $level = $claims['level'] ?? null;
        $sid = $claims['sid'] ?? null;
        $jti = $claims['jti'] ?? null;
        $exp = $claims['exp'] ?? null;
        $mfaAt = $claims['mfa_at'] ?? null;

        if (($claims['area'] ?? null) !== 'saas'
            || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($level) || ! in_array($level, self::LEVELS, true)
            || ! is_string($sid) || preg_match('/^[0-9a-f]{32}$/', $sid) !== 1
            || ! is_string($jti) || $jti === '' || strlen($jti) > 100
            || ! is_int($exp) || $exp - $now > 120) {
            throw new StaffAccessDenied('contenuto non valido');
        }

        // La sessione centrale deve essere ancora valida e la persona deve avere ancora quel ruolo.
        if ($this->check($sid) === null) {
            throw new StaffAccessDenied('sessione non valida');
        }

        return new StaffGrant(mb_strtolower($email), $level, $sid, $jti, $exp, is_int($mfaAt) ? $mfaAt : null);
    }

    public function check(string $sid): ?string
    {
        $person = $this->directory->personOfSession($sid);
        $level = $person === null ? null : ($this->directory->areas($person)['saas'] ?? null);

        return is_string($level) && in_array($level, self::LEVELS, true) ? $level : null;
    }
}
