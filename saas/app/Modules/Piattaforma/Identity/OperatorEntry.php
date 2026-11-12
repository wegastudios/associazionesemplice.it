<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use App\Modules\Portale\Contracts\StaffAccessDenied;
use App\Modules\Portale\Contracts\StaffJwt;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;

/**
 * Ingresso degli operatori delle associazioni nel gestionale dal login unico (docs/LOGIN_UNICO_E_DATABASE.md, passo 6).
 * Il portale chiede, con la sessione centrale della persona, un biglietto `operator_sso` (60 secondi, monouso); il gestionale
 * lo consuma su `/accesso-unico` e apre la sessione dell'utente con la stessa email. Nessuna password passa da qui.
 * Acceso solo con SAAS_UNIFIED_OPERATORS (spento di default).
 */
final readonly class OperatorEntry
{
    private const AUDIENCE = 'associazionesemplice-gestionale';

    public function __construct(private StaffIdentityConfig $identity, private StaffAccess $access, private IdentityService $sessions, private DatabaseManager $db, private Repository $config) {}

    public function enabled(): bool
    {
        return $this->config->get('saas.unified_login.operators') === true && $this->identity->enabled();
    }

    /** Dove va chi non ha accesso al gestionale o scade il biglietto. */
    public function accountUrl(): string
    {
        $public = $this->config->get('saas.public_url');

        return rtrim(is_string($public) && $public !== '' ? $public : 'https://associazionesemplice.it', '/').'/account';
    }

    /** L'indirizzo d'ingresso per la sessione centrale `$sid`, o null se la persona non è operatore di un'associazione attiva. */
    public function urlFor(string $sid): ?string
    {
        $person = $this->sessions->personOf($sid);
        if ($person === null || $this->operatorUserId($person->email) === null) {
            return null;
        }
        $now = time();
        $token = StaffJwt::sign([
            'iss' => $this->identity->issuer(),
            'aud' => self::AUDIENCE,
            'typ' => 'operator_sso',
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => bin2hex(random_bytes(16)),
            'sub' => $person->email,
            'sid' => $sid,
            'mfa' => $this->access->hasTwoFactor($person),
        ], $this->identity->signingSeed(), 'staff-');

        return $this->appBase().'/accesso-unico?t='.rawurlencode($token);
    }

    /**
     * Consuma il biglietto: l'id dell'utente del gestionale e se il secondo livello è stato verificato all'accesso, o null.
     *
     * @return array{user: string, mfa: bool}|null
     */
    public function consume(string $token): ?array
    {
        $kid = StaffJwt::kidOf($token);
        $seed = $this->identity->signingSeed();
        if ($kid === null || $seed === '') {
            return null;
        }
        $public = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed))), '+/', '-_'), '=');
        try {
            $claims = StaffJwt::verify($token, [$kid => $public], $this->identity->issuer(), self::AUDIENCE, 'operator_sso', time());
        } catch (StaffAccessDenied) {
            return null;
        }
        $email = $claims['sub'] ?? null;
        $sid = $claims['sid'] ?? null;
        $jti = $claims['jti'] ?? null;
        if (! is_string($email) || ! is_string($sid) || ! is_string($jti) || $jti === '' || strlen($jti) > 100) {
            return null;
        }
        // Monouso, e la sessione centrale deve essere ancora valida.
        if (! Cache::add('operator-sso:'.$jti, 1, 180) || $this->sessions->personOf($sid) === null) {
            return null;
        }
        $user = $this->operatorUserId($email);

        return $user === null ? null : ['user' => $user, 'mfa' => ($claims['mfa'] ?? false) === true];
    }

    /** L'utente del gestionale con questa email, attivo e operatore di almeno un'associazione. */
    private function operatorUserId(string $email): ?string
    {
        $con = $this->db->connection('pgsql');
        $id = $con->table('core.users')->where('email', mb_strtolower($email))->whereNull('disabled_at')->value('id');

        return is_string($id) && $con->table('core.org_users')->where('user_id', $id)->exists() ? $id : null;
    }

    private function appBase(): string
    {
        $url = $this->config->get('app.url');

        return rtrim(is_string($url) && $url !== '' ? $url : 'https://app.associazionesemplice.it', '/');
    }
}
