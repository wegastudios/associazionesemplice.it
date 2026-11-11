<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Piattaforma\Identity\AreaRequestVerifier;
use App\Modules\Piattaforma\Identity\StaffAreas;
use App\Modules\Piattaforma\Identity\StaffDirectory;
use App\Modules\Piattaforma\Identity\StaffIdentityConfig;
use App\Modules\Piattaforma\Identity\StaffTokenIssuer;
use App\Modules\Portale\Contracts\StaffAccessDenied;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Richieste firmate delle aree (WordPress, Supporto) al SaaS come fornitore di identità dello staff
 * (docs/UTENTI_E_ACCESSI.md). Corpo JSON `{"token": "<JWT>"}`; nessuna sessione, nessun cookie. Spento (404) finché
 * SAAS_STAFF_ISSUER non è acceso. Gli errori sono volutamente generici: non dicono se l'email esiste.
 */
final readonly class StaffApiController
{
    /** Tentativi di accesso falliti per email e indirizzo prima del blocco di un minuto. */
    private const MAX_FAILED_LOGINS = 5;

    public function __construct(
        private StaffIdentityConfig $config,
        private AreaRequestVerifier $requests,
        private StaffDirectory $directory,
        private StaffTokenIssuer $issuer,
    ) {}

    /** `staff_login`: email e password nel modulo di accesso di un'area → `staff_sso` per quell'area. */
    public function login(Request $request): JsonResponse
    {
        $verified = $this->verified($request, 'staff_login');
        if ($verified instanceof JsonResponse) {
            return $verified;
        }
        ['area' => $area, 'claims' => $claims] = $verified;

        $email = $claims['email'] ?? null;
        $password = $claims['password'] ?? null;
        $ip = $this->ip($claims['ip'] ?? null);
        if (! is_string($email) || ! is_string($password) || $password === '' || strlen($password) > 1024 || strlen($email) > 254) {
            return $this->denied(422);
        }

        $key = 'staff-login|'.mb_strtolower($email).'|'.$ip;
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILED_LOGINS)) {
            $this->directory->audit('staff.login_blocked', null, mb_strtolower($email), ['area' => $area], $ip);

            return $this->denied(429);
        }

        $person = $this->directory->authenticate($email, $password);
        $areas = $person === null ? [] : $this->directory->areas($person);
        if ($person === null || ! isset($areas[$area])) {
            RateLimiter::hit($key, 60);
            $this->directory->audit('staff.login_failed', $person?->id, mb_strtolower($email), ['area' => $area], $ip);

            return $this->denied(401);
        }
        RateLimiter::clear($key);

        $sid = $this->directory->openSession($person, $ip);
        $this->directory->audit('staff.login', $person->id, null, ['area' => $area, 'level' => $areas[$area]], $ip);

        return response()->json(['token' => $this->issuer->issue($person, $area, $sid, $areas)]);
    }

    /** `staff_token`: «Vai a» un'altra area con la sessione centrale già aperta (`sid`, destinazione in `to`). */
    public function token(Request $request): JsonResponse
    {
        $verified = $this->verified($request, 'staff_token');
        if ($verified instanceof JsonResponse) {
            return $verified;
        }
        $claims = $verified['claims'];

        $sid = $claims['sid'] ?? null;
        $to = $claims['to'] ?? null;
        $sid = is_string($sid) && preg_match('/^[0-9a-f]{32}$/', $sid) === 1 ? $sid : null;
        $person = $sid === null ? null : $this->directory->personOfSession($sid);
        $areas = $person === null ? [] : $this->directory->areas($person);
        if ($person === null || ! is_string($to) || ! StaffAreas::exists($to) || ! isset($areas[$to])) {
            return $this->denied(403);
        }
        $this->directory->audit('staff.jump', $person->id, null, ['from' => $verified['area'], 'to' => $to], null);

        return response()->json(['token' => $this->issuer->issue($person, $to, $sid ?? '', $areas)]);
    }

    /** `staff_session_check`: la sessione centrale è ancora valida e a quale livello (ogni 5 minuti, da ogni area). */
    public function check(Request $request): JsonResponse
    {
        $verified = $this->verified($request, 'staff_session_check');
        if ($verified instanceof JsonResponse) {
            return $verified;
        }

        $sid = $verified['claims']['sid'] ?? null;
        $sid = is_string($sid) && preg_match('/^[0-9a-f]{32}$/', $sid) === 1 ? $sid : null;
        $person = $sid === null ? null : $this->directory->personOfSession($sid);
        $areas = $person === null ? [] : $this->directory->areas($person);
        $level = $areas[$verified['area']] ?? null;
        if ($person === null || $level === null) {
            return response()->json(['valid' => false, 'level' => null, 'areas' => (object) []]);
        }

        return response()->json(['valid' => true, 'level' => $level, 'areas' => $areas]);
    }

    /** @return array{area: string, claims: array<string, mixed>}|JsonResponse */
    private function verified(Request $request, string $type): array|JsonResponse
    {
        if (! $this->config->enabled()) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $token = $request->json('token');
        if (! is_string($token) || $token === '') {
            return $this->denied(422);
        }
        try {
            return $this->requests->verify($token, $type);
        } catch (StaffAccessDenied) {
            return $this->denied(401);
        }
    }

    private function denied(int $status): JsonResponse
    {
        return response()->json(['error' => 'denied'], $status);
    }

    private function ip(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }
}
