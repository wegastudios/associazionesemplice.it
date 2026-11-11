<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Staff\StaffJwt;
use App\Services\Staff\StaffPortal;
use App\Services\Staff\StaffSession;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::user()?->isStaff()) {
            return redirect()->route('admin.home');
        }

        return view('admin.login', [
            'remoteLogin' => (bool) config('support.staff_sso.remote_login'),
            'localLogin' => (bool) config('support.staff_sso.local_login'),
        ]);
    }

    public function login(Request $request, AuditLogger $audit, StaffPortal $portal, StaffSession $session): RedirectResponse
    {
        $remote = (bool) config('support.staff_sso.remote_login');
        abort_unless($remote || config('support.staff_sso.local_login'), 403, 'L\'accesso con password è disattivato.');

        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'code' => 'nullable|string|max:20']);
        $throttleKey = Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withInput($request->only('email'))
                ->withErrors(['email' => "Troppi tentativi. Riprova tra {$seconds} secondi."]);
        }

        // Messaggio unico per credenziali errate o utente non amministratore: nessuna enumerazione
        $fail = fn () => back()->withInput($request->only('email'))->withErrors(['email' => 'Credenziali non valide.']);

        // Verifica nel SaaS: l'elenco dello staff e le password stanno lì. Se non va, solo con l'accesso locale acceso (emergenza) si prova il locale.
        if ($remote) {
            $token = $portal->login(Str::lower($data['email']), $data['password'], $data['code'] ?? null, $request->ip());
            $claims = $token === null ? null : StaffJwt::verifySso($token);
            $user = $claims === null ? null : $session->signIn($request, $claims);
            if ($user !== null) {
                RateLimiter::clear($throttleKey);
                $audit->record('admin.login_remote', $user, ['level' => $claims['level'], 'mfa' => $claims['mfa']], Actor::user($user->id));

                return redirect()->route('admin.home');
            }
            if (! config('support.staff_sso.local_login')) {
                RateLimiter::hit($throttleKey, 300);
                $audit->record('admin.login_failed', null, ['email' => $data['email']]);

                return $fail();
            }
        }

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']])) {
            RateLimiter::hit($throttleKey, 300);
            $audit->record('admin.login_failed', null, ['email' => $data['email']]);

            return $fail();
        }

        if (! Auth::user()->isStaff() || Auth::user()->sso_managed) {
            Auth::logout();
            RateLimiter::hit($throttleKey, 300);
            $audit->record('admin.login_denied', null, ['email' => $data['email']]);

            return $fail();
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $audit->record('admin.login', null, [], Actor::user(Auth::id()));

        return redirect()->intended(route('admin.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
