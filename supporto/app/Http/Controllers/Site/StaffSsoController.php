<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Staff\StaffJwt;
use App\Services\Staff\StaffSession;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Accesso unico dello STAFF dal portale (token staff_sso, docs/STAFF_SSO.md): il portale decide chi è staff e con quale livello
 * (admin | agente); qui si apre una sessione propria, senza password locale. I clienti entrano da /sso e non cambiano.
 */
class StaffSsoController extends Controller
{
    public function login(Request $request, AuditLogger $audit, StaffSession $session): RedirectResponse
    {
        $claims = StaffJwt::verifySso((string) $request->input('token'));
        $user = $claims === null ? null : $session->signIn($request, $claims);

        // Un solo rifiuto per ogni problema: non si dice perché
        if ($user === null) {
            $audit->record('admin.staff_sso_denied', null, ['ip' => $request->ip()]);

            return redirect()->route('admin.login')->withErrors(['email' => 'Il collegamento non è valido o è scaduto: entra di nuovo.']);
        }

        $audit->record('admin.staff_sso_login', $user, ['level' => $claims['level'], 'mfa' => $claims['mfa']], Actor::user($user->id));

        // Sempre alla home dell'area: nessun indirizzo di ritorno ricevuto
        return redirect()->route('admin.home');
    }
}
