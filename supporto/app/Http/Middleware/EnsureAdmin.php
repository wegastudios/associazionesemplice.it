<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accesso al pannello riservato agli amministratori del sito (users.global_role = admin).
 * Gli utenti creati dall'accesso unico restano validi solo 8 ore dall'ultimo passaggio dal portale: se il portale non li
 * dichiara piu staff non rientrano, e una sessione rimasta aperta non dura oltre.
 */
class EnsureAdmin
{
    public const SSO_STAFF_HOURS = 8;

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect()->guest(route('admin.login'));
        }

        if ($user->sso_managed && (now()->timestamp - (int) $request->session()->get('sso_staff_at', 0)) > self::SSO_STAFF_HOURS * 3600) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect(rtrim((string) config('support.sso.portal'), '/').'/supporto/accedi');
        }

        abort_unless($user->isAdmin(), 403, 'Accesso riservato agli amministratori.');

        return $next($request);
    }
}
