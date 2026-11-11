<?php

namespace App\Services\Staff;

use Illuminate\Support\Facades\Http;

/** Richieste firmate del supporto verso il portale: riconferma della sessione e pagina «Utenti» (docs/STAFF_SSO.md §3 e §4). */
class StaffPortal
{
    private function url(string $path): string
    {
        return rtrim((string) config('support.staff_sso.issuer'), '/').'/api/staff/v1/'.$path;
    }

    /**
     * Login dello staff verificato dal SaaS (email, password ed eventuale codice a due passaggi): in risposta un token staff_sso,
     * che poi si verifica e si usa come tutti gli altri. Password e codice passano solo in questa richiesta firmata, mai salvati.
     *
     * @return string|null il token staff_sso, oppure null (credenziali errate, portale muto: l'esito è sempre generico)
     */
    public function login(string $email, string $password, ?string $code, ?string $ip): ?string
    {
        $claims = ['area' => 'supporto', 'email' => $email, 'password' => $password];
        if ($code !== null && $code !== '') {
            $claims['code'] = $code;
        }
        if ($ip !== null) {
            $claims['ip'] = $ip;
        }
        try {
            $response = Http::asForm()->timeout(8)->post($this->url('login'), ['token' => StaffJwt::sign('staff_login', $claims)]);
        } catch (\Throwable) {
            return null;
        }
        $data = $response->json();
        $token = is_array($data) ? ($data['staff_sso'] ?? $data['token'] ?? null) : null;

        return $response->ok() && is_string($token) ? $token : null;
    }

    /**
     * Riconferma la sessione centrale.
     *
     * @return array{valid: bool, level: ?string}|null null = il portale non risponde («non so»)
     */
    public function checkSession(string $sid): ?array
    {
        try {
            $response = Http::asForm()->timeout(5)->post($this->url('session-check'), [
                'token' => StaffJwt::sign('staff_session_check', ['sid' => $sid, 'area' => 'supporto']),
            ]);
        } catch (\Throwable) {
            return null;
        }
        $data = $response->json();
        if (! $response->ok() || ! is_array($data) || ! is_bool($data['valid'] ?? null)) {
            return null;
        }

        return ['valid' => $data['valid'], 'level' => is_string($data['level'] ?? null) ? $data['level'] : null];
    }

    /**
     * Una richiesta «Utenti»: list | invite | grant | revoke | deactivate. I permessi li decide il portale.
     *
     * @param  array<string, string>  $extra
     * @return array{status: int, body: array<string, mixed>}
     */
    public function people(string $action, string $actor, array $extra = []): array
    {
        try {
            $response = Http::asForm()->timeout(8)->post($this->url('people/'.$action), [
                'token' => StaffJwt::sign('staff_people', ['area' => 'supporto', 'act' => $actor] + $extra),
            ]);
        } catch (\Throwable) {
            return ['status' => 0, 'body' => []];
        }
        $body = $response->json();

        return ['status' => $response->status(), 'body' => is_array($body) ? $body : []];
    }
}
