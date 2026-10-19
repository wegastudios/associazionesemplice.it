<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
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
        if (Auth::user()?->isAdmin()) {
            return redirect()->route('admin.home');
        }

        return view('admin.login');
    }

    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $throttleKey = Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withInput($request->only('email'))
                ->withErrors(['email' => "Troppi tentativi. Riprova tra {$seconds} secondi."]);
        }

        // Messaggio unico per credenziali errate o utente non amministratore: nessuna enumerazione
        $fail = fn () => back()->withInput($request->only('email'))->withErrors(['email' => 'Credenziali non valide.']);

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']])) {
            RateLimiter::hit($throttleKey, 300);
            $audit->record('admin.login_failed', null, ['email' => $data['email']]);

            return $fail();
        }

        if (! Auth::user()->isAdmin() || Auth::user()->sso_managed) {
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
