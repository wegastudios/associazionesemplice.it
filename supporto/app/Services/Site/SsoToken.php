<?php

namespace App\Services\Site;

use Illuminate\Support\Facades\Cache;

/**
 * Verifica del token di accesso unico emesso dal portale associazionesemplice.it:
 * base64url(payload JSON) . "." . base64url(HMAC-SHA256(payload, segreto)); vale 60 secondi e si usa una sola volta.
 */
class SsoToken
{
    /** @return array{sub: string, role: string, name: ?string, plan: ?string}|null */
    public static function verify(string $token, ?int $now = null): ?array
    {
        $secret = (string) config('support.sso.secret');
        if (strlen($secret) < 32 || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $sig] = explode('.', $token);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
        if (! hash_equals($expected, $sig)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $now ??= time();
        if (! is_array($data) || ($data['v'] ?? null) !== 1
            || rtrim((string) ($data['aud'] ?? ''), '/') !== rtrim((string) config('app.url'), '/')
            || rtrim((string) ($data['iss'] ?? ''), '/') !== rtrim((string) config('support.sso.portal'), '/')
            || (int) ($data['exp'] ?? 0) < $now || (int) ($data['iat'] ?? PHP_INT_MAX) > $now + 30
            || ! in_array($data['role'] ?? null, ['admin', 'support', 'customer'], true)
            || ! filter_var($data['sub'] ?? null, FILTER_VALIDATE_EMAIL)
            || ! is_string($data['jti'] ?? null) || strlen($data['jti']) < 16) {
            return null;
        }
        // Un token si usa una sola volta
        if (! Cache::add('sso:jti:'.$data['jti'], 1, 300)) {
            return null;
        }

        return [
            'sub' => mb_strtolower($data['sub']),
            'role' => $data['role'],
            'name' => isset($data['name']) && is_string($data['name']) && $data['name'] !== '' ? mb_substr($data['name'], 0, 150) : null,
            'plan' => isset($data['plan']) && is_string($data['plan']) && $data['plan'] !== '' ? mb_substr($data['plan'], 0, 120) : null,
        ];
    }
}
