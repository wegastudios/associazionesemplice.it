<?php

namespace App\Services\Staff;

use Illuminate\Support\Facades\Cache;

/**
 * JWT EdDSA (Ed25519) dell'accesso staff unico, formato di docs/STAFF_SSO.md: intestazione {"typ":"JWT","alg":"EdDSA","kid":"..."},
 * base64url senza "=", tolleranza sull'orologio 60 secondi, campi sconosciuti rifiutati.
 */
class StaffJwt
{
    private const SKEW = 60;

    /** @var array<int, string> claim ammessi nel token staff_sso */
    private const SSO_CLAIMS = ['iss', 'aud', 'typ', 'iat', 'exp', 'jti', 'sub', 'area', 'level', 'mfa', 'mfa_at', 'sid', 'areas'];

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $text): ?string
    {
        $raw = base64_decode(strtr($text, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }

    /** @return array<string, string> kid => chiave pubblica (base64url) del portale */
    public static function portalKeys(): array
    {
        return self::parseKeys((string) config('support.staff_sso.portal_keys'));
    }

    /** @return array<string, string> */
    private static function parseKeys(string $list): array
    {
        $keys = [];
        foreach (explode(',', $list) as $pair) {
            [$kid, $x] = array_pad(explode(':', trim($pair), 2), 2, '');
            if ($kid !== '' && $x !== '') {
                $keys[$kid] = $x;
            }
        }

        return $keys;
    }

    /**
     * Verifica un token staff_sso del portale: firma, kid fissato, typ, aud, iss, durata (massimo 60 secondi), uso singolo (jti).
     *
     * @return array<string, mixed>|null i claim, oppure null se qualcosa non torna
     */
    public static function verifySso(string $token, ?int $now = null): ?array
    {
        $now ??= time();
        $claims = self::decode($token, self::portalKeys());
        if ($claims === null || array_diff(array_keys($claims), self::SSO_CLAIMS) !== []) {
            return null;
        }
        $portal = rtrim((string) config('support.staff_sso.issuer'), '/');
        if (($claims['typ'] ?? null) !== 'staff_sso'
            || ($claims['aud'] ?? null) !== config('support.staff_sso.audience')
            || rtrim((string) ($claims['iss'] ?? ''), '/') !== $portal
            || ! is_int($claims['iat'] ?? null) || ! is_int($claims['exp'] ?? null)
            || $claims['exp'] - $claims['iat'] > 60 || $claims['exp'] < $now - self::SKEW || $claims['iat'] > $now + self::SKEW
            || ! is_string($claims['jti'] ?? null) || strlen($claims['jti']) < 16 || strlen($claims['jti']) > 100
            || ! is_bool($claims['mfa'] ?? null) || ! self::validAreas($claims['areas'] ?? [])) {
            return null;
        }
        if (! Cache::add('staff:jti:'.$claims['jti'], 1, max(60, $claims['exp'] - $now + 2 * self::SKEW))) {
            return null;
        }

        return $claims;
    }

    /** `areas` (area => livello) serve al selettore «Vai a»: solo coppie di testi brevi, mai altro. */
    private static function validAreas(mixed $areas): bool
    {
        if (! is_array($areas) || count($areas) > 10) {
            return false;
        }
        foreach ($areas as $area => $level) {
            if (! is_string($area) || ! preg_match('/^[a-z]{1,20}$/', $area) || ! is_string($level) || ! preg_match('/^[a-z]{1,20}$/', $level)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> kid => chiave pubblica (base64url) con cui il SaaS firma i token dei clienti (chiavi `cust-…`, separate dallo staff) */
    public static function customerKeys(): array
    {
        return self::parseKeys((string) config('support.customer_sso.keys'));
    }

    /**
     * Verifica un token customer_sso del SaaS: firma, kid dei clienti, typ, aud, iss, durata (massimo 60 secondi), uso singolo (jti).
     * Il cliente è identificato da `sub` (id nel SaaS) ed `email`; `name` e `plans` sono facoltativi; campi sconosciuti rifiutati.
     *
     * @return array<string, mixed>|null
     */
    public static function verifyCustomer(string $token, ?int $now = null): ?array
    {
        $now ??= time();
        $claims = self::decode($token, self::customerKeys());
        if ($claims === null || array_diff(array_keys($claims), ['iss', 'aud', 'typ', 'iat', 'exp', 'jti', 'sub', 'email', 'name', 'plans']) !== []) {
            return null;
        }
        $sub = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (($claims['typ'] ?? null) !== 'customer_sso'
            || ($claims['aud'] ?? null) !== config('support.staff_sso.audience')
            || rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim((string) config('support.staff_sso.issuer'), '/')
            || ! is_int($claims['iat'] ?? null) || ! is_int($claims['exp'] ?? null)
            || $claims['exp'] - $claims['iat'] > 60 || $claims['exp'] < $now - self::SKEW || $claims['iat'] > $now + self::SKEW
            || ! is_string($claims['jti'] ?? null) || strlen($claims['jti']) < 16 || strlen($claims['jti']) > 100
            || ! (is_int($sub) || (is_string($sub) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sub)))
            || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $email !== mb_strtolower($email)) {
            return null;
        }
        if (! Cache::add('customer:jti:'.$claims['jti'], 1, max(60, $claims['exp'] - $now + 2 * self::SKEW))) {
            return null;
        }

        return $claims;
    }

    /**
     * @param  array<string, string>  $keys
     * @return array<string, mixed>|null
     */
    public static function decode(string $token, array $keys): ?array
    {
        if (strlen($token) > 4096 || substr_count($token, '.') !== 2) {
            return null;
        }
        [$h, $p, $s] = explode('.', $token);
        $header = json_decode((string) self::unb64($h), true);
        $payload = json_decode((string) self::unb64($p), true);
        $sig = self::unb64($s);
        if (! is_array($header) || ! is_array($payload) || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        if (array_diff(array_keys($header), ['typ', 'alg', 'kid']) !== []
            || ($header['typ'] ?? null) !== 'JWT' || ($header['alg'] ?? null) !== 'EdDSA'
            || ! is_string($header['kid'] ?? null) || ! isset($keys[$header['kid']])) {
            return null;
        }
        $public = self::unb64($keys[$header['kid']]);
        if ($public === null || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($sig, $h.'.'.$p, $public)) {
            return null;
        }

        return $payload;
    }

    /**
     * Firma una richiesta del supporto verso il portale (staff_session_check, staff_people): valida 120 secondi, monouso.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function sign(string $typ, array $claims): string
    {
        $key = self::privateKey();
        $now = time();
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => $key['kid']]));
        $payload = self::b64(json_encode([
            'iss' => rtrim((string) config('app.url'), '/'),
            'aud' => rtrim((string) config('support.staff_sso.issuer'), '/'),
            'typ' => $typ,
            'iat' => $now,
            'exp' => $now + 120,
            'jti' => bin2hex(random_bytes(16)),
        ] + $claims, JSON_UNESCAPED_SLASHES));

        return $header.'.'.$payload.'.'.self::b64(sodium_crypto_sign_detached($header.'.'.$payload, $key['secret']));
    }

    /** @return array{kid: string, secret: string} */
    private static function privateKey(): array
    {
        $file = (string) config('support.staff_sso.private_key_file');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $secret = is_array($data) ? self::unb64((string) ($data['d'] ?? '')) : null;
        if (! is_array($data) || ! is_string($data['kid'] ?? null) || $secret === null || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Chiave di firma del supporto mancante: si crea con `php artisan staff:key`.');
        }

        return ['kid' => $data['kid'], 'secret' => $secret];
    }
}
