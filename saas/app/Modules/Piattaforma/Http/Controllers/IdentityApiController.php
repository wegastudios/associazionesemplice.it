<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Piattaforma\Identity\AreaRequestVerifier;
use App\Modules\Piattaforma\Identity\IdentityService;
use App\Modules\Piattaforma\Identity\OperatorEntry;
use App\Modules\Piattaforma\Identity\StaffAccess;
use App\Modules\Piattaforma\Identity\StaffAreas;
use App\Modules\Piattaforma\Identity\StaffDirectory;
use App\Modules\Piattaforma\Identity\StaffIdentityConfig;
use App\Modules\Piattaforma\Identity\StaffTokenIssuer;
use App\Modules\Portale\Contracts\StaffAccessDenied;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Le richieste del portale all'identità unica (docs/IDENTITA_API.md): accesso, codice, profilo, password, email,
 * secondo livello, sessioni, recupero, passaggio alle aree. Corpo `{"token": "<JWT>"}` firmato dal portale (area
 * `wordpress`), `typ` = `identity_<azione>`; nessuna sessione né cookie. 404 finché SAAS_STAFF_ISSUER non è acceso.
 * Errori generici: mai «email inesistente».
 */
final readonly class IdentityApiController
{
    private const MAX_FAILED = 5;

    public function __construct(
        private StaffIdentityConfig $config,
        private AreaRequestVerifier $requests,
        private IdentityService $identity,
        private StaffAccess $access,
        private StaffDirectory $directory,
        private StaffTokenIssuer $issuer,
        private OperatorEntry $operators,
    ) {}

    public function handle(Request $request, string $action): JsonResponse
    {
        if (! $this->config->enabled()) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $token = $request->json('token');
        if (! is_string($token) || $token === '') {
            return $this->denied(422);
        }
        try {
            $verified = $this->requests->verify($token, 'identity_'.str_replace('-', '_', $action));
        } catch (StaffAccessDenied) {
            return $this->denied(401);
        }
        // Solo il portale parla a nome delle persone: la chiave dell'area wordpress.
        if ($verified['area'] !== 'wordpress') {
            return $this->denied(403);
        }
        $c = $verified['claims'];
        $ip = is_string($c['ip'] ?? null) && filter_var($c['ip'], FILTER_VALIDATE_IP) !== false ? $c['ip'] : null;
        $sid = $this->str($c, 'sid', 32);

        return match ($action) {
            'login' => $this->login($c, $ip),
            'code' => $this->result($this->identity->code($this->str($c, 'ticket', 100), $this->str($c, 'code', 10), $ip)),
            'session' => response()->json($this->identity->session($sid)),
            'profile' => $this->result($this->identity->profile($sid)),
            'password' => $this->guarded($sid, fn () => $this->messages($this->identity->changePassword($sid, $this->str($c, 'current', 1024), $this->str($c, 'new', 1024), $ip))),
            'email' => $this->guarded($sid, fn () => $this->emailChange($c, $sid, $ip)),
            'email-confirm' => $this->flag($this->identity->confirmEmailChange($this->str($c, 'code', 100), $ip)),
            '2fa-start' => $this->guarded($sid, fn () => $this->result($this->identity->startTwoFactor($sid, $this->str($c, 'password', 1024)))),
            '2fa-confirm' => $this->guarded($sid, fn () => $this->flag($this->identity->confirmTwoFactor($sid, $this->str($c, 'code', 10), $ip))),
            '2fa-disable' => $this->guarded($sid, fn () => $this->flag($this->identity->disableTwoFactor($sid, $this->str($c, 'password', 1024), $this->str($c, 'code', 10), $ip))),
            'sessions' => $this->result($this->identity->profile($sid)),
            'session-revoke' => $this->flag($this->identity->revokeSession($sid, $this->str($c, 'target', 40))),
            'logout' => $this->logout($sid),
            'reset-request' => $this->resetRequest($c, $ip),
            'reset-complete' => $this->messages($this->access->reset($this->str($c, 'code', 100), $this->str($c, 'password', 1024), $this->str($c, 'password', 1024), $ip)),
            'token' => $this->areaToken($sid, $this->str($c, 'to', 20)),
            'operator-entry' => $this->operatorEntry($sid),
            default => $this->denied(404),
        };
    }

    /** @param  array<string, mixed>  $c */
    private function login(array $c, ?string $ip): JsonResponse
    {
        $email = $this->str($c, 'email', 254);
        $password = $this->str($c, 'password', 1024);
        if ($email === '' || $password === '') {
            return $this->denied(422);
        }
        $key = 'staff-login|'.mb_strtolower($email).'|'.($ip ?? '');
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILED)) {
            $this->directory->audit('staff.login_blocked', null, mb_strtolower($email), [], $ip);

            return $this->denied(429);
        }
        $result = $this->identity->login($email, $password, $ip);
        if ($result === null) {
            RateLimiter::hit($key, 60);

            return $this->denied(401);
        }
        RateLimiter::clear($key);

        return response()->json($result);
    }

    /** @param  array<string, mixed>  $c */
    private function emailChange(array $c, string $sid, ?string $ip): JsonResponse
    {
        $prefix = $this->linkPrefix($c);

        return $prefix === null
            ? $this->denied(422)
            : $this->messages($this->identity->requestEmailChange($sid, $this->str($c, 'password', 1024), $this->str($c, 'new_email', 254), $prefix, $ip));
    }

    /**
     * Sempre ok: la mail parte solo se l'email esiste.
     *
     * @param  array<string, mixed>  $c
     */
    private function resetRequest(array $c, ?string $ip): JsonResponse
    {
        $prefix = $this->linkPrefix($c);
        $email = $this->str($c, 'email', 254);
        if ($prefix === null || $email === '') {
            return $this->denied(422);
        }
        $key = 'staff-reset|'.mb_strtolower($email).'|'.($ip ?? '');
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 600);
            $this->access->requestReset($email, $prefix, $ip);
        }

        return response()->json(['ok' => true]);
    }

    private function logout(string $sid): JsonResponse
    {
        $this->identity->logout($sid);

        return response()->json(['ok' => true]);
    }

    private function areaToken(string $sid, string $to): JsonResponse
    {
        $person = $this->identity->personOf($sid);
        $areas = $person === null ? [] : $this->directory->areas($person);
        if ($person === null || ! StaffAreas::exists($to) || ! isset($areas[$to])) {
            return $this->denied(403);
        }
        $this->directory->audit('staff.jump', $person->id, null, ['from' => 'account', 'to' => $to], null);

        return response()->json(['token' => $this->issuer->issue($person, $to, $sid, $areas)]);
    }

    /** L'indirizzo d'ingresso nel gestionale per un operatore (passo 6); 403 se non lo è o se l'ingresso unico è spento. */
    private function operatorEntry(string $sid): JsonResponse
    {
        $url = $this->operators->enabled() ? $this->operators->urlFor($sid) : null;

        return $url === null ? $this->denied(403) : response()->json(['url' => $url]);
    }

    /**
     * Le azioni che chiedono la password: al massimo 5 errori al minuto per sessione.
     *
     * @param  callable(): JsonResponse  $action
     */
    private function guarded(string $sid, callable $action): JsonResponse
    {
        $key = 'identity-sensitive|'.$sid;
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILED)) {
            return $this->denied(429);
        }
        $response = $action();
        if ($response->getStatusCode() !== 200) {
            RateLimiter::hit($key, 60);
        }

        return $response;
    }

    /**
     * Il prefisso del link nelle mail: solo indirizzi del portale (mai uno scelto da chi chiama).
     *
     * @param  array<string, mixed>  $c
     */
    private function linkPrefix(array $c): ?string
    {
        $prefix = $this->str($c, 'link_base', 300);
        $origin = rtrim($this->portalOrigin(), '/');

        return $origin !== '' && str_starts_with($prefix, $origin.'/') && ! str_contains($prefix, '@') ? $prefix : null;
    }

    private function portalOrigin(): string
    {
        $public = config('saas.public_url');
        $portal = config('saas.portal.url');

        return is_string($public) && $public !== '' ? $public : (is_string($portal) ? $portal : '');
    }

    /** @param  list<string>  $errors */
    private function messages(array $errors): JsonResponse
    {
        return $errors === [] ? response()->json(['ok' => true]) : response()->json(['error' => 'invalid', 'messages' => $errors], 422);
    }

    /** @param  array<string, mixed>|null  $data */
    private function result(?array $data): JsonResponse
    {
        return $data === null ? $this->denied(401) : response()->json($data);
    }

    private function flag(bool $ok): JsonResponse
    {
        return $ok ? response()->json(['ok' => true]) : $this->denied(401);
    }

    /** @param  array<string, mixed>  $claims */
    private function str(array $claims, string $key, int $max): string
    {
        $value = $claims[$key] ?? null;

        return is_string($value) && strlen($value) <= $max ? $value : '';
    }

    private function denied(int $status): JsonResponse
    {
        return response()->json(['error' => 'denied'], $status);
    }
}
