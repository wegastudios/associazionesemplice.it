<?php

declare(strict_types=1);

namespace App\Modules\Portale\Staff;

use App\Modules\Portale\Contracts\StaffAccess;
use App\Modules\Portale\Contracts\StaffAccessDenied;
use App\Modules\Portale\Contracts\StaffGrant;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\Jwt\InvalidToken;
use App\Modules\Portale\PortalConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class PortalStaffAccess implements StaffAccess
{
    /** Il secondo fattore non deve avere più di 8 ore (UTENTI_E_ACCESSI §5). */
    private const MFA_MAX_AGE = 8 * 3600;

    public function __construct(private PortalConfig $portal, private Repository $config) {}

    public function enabled(): bool
    {
        return $this->config->get('saas.staff_sso.enabled') === true;
    }

    public function loginUrl(): string
    {
        $url = $this->config->get('saas.staff_sso.login_url');

        return is_string($url) && $url !== '' ? $url : $this->portal->portalUrl().'/staff/accedi?area=saas';
    }

    public function verify(string $token): StaffGrant
    {
        $now = time();
        try {
            $claims = EdDsaJwt::verify($token, $this->portal->portalKeys(), $this->portal->portalUrl(), PortalConfig::AUDIENCE, 'staff_sso', $now);
        } catch (InvalidToken) {
            throw new StaffAccessDenied('token non valido');
        }

        $email = $claims['sub'] ?? null;
        $level = $claims['level'] ?? null;
        $sid = $claims['sid'] ?? null;
        $jti = $claims['jti'] ?? null;
        $exp = $claims['exp'] ?? null;
        $mfaAt = $claims['mfa_at'] ?? null;
        $mfa = $claims['mfa'] ?? null;

        // Il secondo fattore è facoltativo (decisione della titolare, 11/11/2026): `mfa` dice solo se la persona l'ha
        // usato; `mfa_at` c'è solo se `mfa` è vero. Non si pretende `mfa: true`.
        $mfaOk = $mfa === false ? $mfaAt === null : ($mfa === true && is_int($mfaAt) && $now - $mfaAt <= self::MFA_MAX_AGE && $mfaAt <= $now + EdDsaJwt::LEEWAY);

        if (($claims['area'] ?? null) !== 'saas'
            || ! $mfaOk
            || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($level) || ! in_array($level, self::LEVELS, true)
            || ! is_string($sid) || $sid === '' || strlen($sid) > 100
            || ! is_string($jti) || $jti === '' || strlen($jti) > 100
            // Token di vita brevissima (60 secondi): uno più lungo non è un token di accesso.
            || ! is_int($exp) || $exp - $now > 120) {
            throw new StaffAccessDenied('contenuto non valido');
        }

        return new StaffGrant(mb_strtolower($email), $level, $sid, $jti, $exp, is_int($mfaAt) ? $mfaAt : null);
    }

    public function check(string $sid): ?string
    {
        try {
            $now = time();
            $token = EdDsaJwt::sign([
                'iss' => $this->portal->saasUrl(),
                'aud' => $this->portal->portalUrl(),
                'typ' => 'staff_session_check',
                'iat' => $now,
                'exp' => $now + 60,
                'jti' => bin2hex(random_bytes(8)),
                'sid' => $sid,
                'area' => 'saas',
            ], $this->portal->signingSeed());

            $response = Http::timeout(5)->acceptJson()->post($this->portal->portalUrl().'/api/staff/v1/session-check', ['token' => $token]);
        } catch (Throwable) {
            throw new StaffAccessDenied('portale non raggiungibile');
        }
        if (! $response->successful()) {
            throw new StaffAccessDenied('risposta non valida');
        }

        $valid = $response->json('valid');
        $level = $response->json('level');
        if (! is_bool($valid)) {
            throw new StaffAccessDenied('risposta non valida');
        }

        return $valid && is_string($level) && in_array($level, self::LEVELS, true) ? $level : null;
    }
}
