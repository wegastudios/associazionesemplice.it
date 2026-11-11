<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Staff\StaffJwt;
use App\Services\Staff\StaffSession;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Accesso unico dello STAFF dal portale (token staff_sso, docs/STAFF_SSO.md): il portale decide chi è staff e con quale livello
 * (admin | agente); qui si apre una sessione propria, senza password locale. I clienti entrano da /sso e non cambiano.
 */
class StaffSsoController extends Controller
{
    public function login(Request $request, AuditLogger $audit, StaffSession $session): RedirectResponse
    {
        $claims = StaffJwt::verifySso((string) $request->input('token'));
        $level = $claims['level'] ?? null;
        $sid = $claims['sid'] ?? null;
        $email = is_string($claims['sub'] ?? null) ? $claims['sub'] : '';

        // Un solo rifiuto per ogni problema: non si dice perché
        if ($claims === null || ($claims['area'] ?? null) !== 'supporto' || ! isset(StaffSession::LEVELS[$level])
            || ! is_string($sid) || ! preg_match('/^[a-f0-9]{32}$/', $sid)
            || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $email !== mb_strtolower($email)) {
            $audit->record('admin.staff_sso_denied', null, ['ip' => $request->ip()]);

            return redirect()->route('admin.login')->withErrors(['email' => 'Il collegamento non è valido o è scaduto: entra di nuovo dal portale.']);
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            // Nessuna password locale utilizzabile: il portale decide chi è staff
            $user = new User(['email' => $email]);
            $user->forceFill(['name' => Str::before($email, '@'), 'password' => Str::random(48), 'sso_managed' => true]);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $user->save();
        $session->open($request, $user, $sid, $level);
        Auth::login($user);
        $request->session()->regenerate();
        $audit->record('admin.staff_sso_login', $user, ['level' => $level, 'mfa' => $claims['mfa']], Actor::user($user->id));

        // Sempre alla home dell'area: nessun indirizzo di ritorno ricevuto
        return redirect()->route('admin.home');
    }
}
