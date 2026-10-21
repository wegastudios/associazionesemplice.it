<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function edit(): View
    {
        return view('admin.password');
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $throttleKey = 'admin-password:'.$user->id;

        // Anche con una sessione aperta, la password attuale non si puo indovinare a raffica
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return back()->withErrors(['current_password' => 'Troppi tentativi. Riprova tra '.RateLimiter::availableIn($throttleKey).' secondi.']);
        }

        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers()],
        ], [
            'password.confirmed' => 'Le due password nuove non coincidono.',
            'password.different' => 'La nuova password deve essere diversa da quella attuale.',
            'password.min' => 'La nuova password deve avere almeno 12 caratteri.',
            'password.letters' => 'La nuova password deve contenere almeno una lettera.',
            'password.numbers' => 'La nuova password deve contenere almeno un numero.',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            RateLimiter::hit($throttleKey, 300);
            $audit->record('admin.password_change_failed', $user, [], Actor::user($user->id));

            return back()->withErrors(['current_password' => 'La password attuale non e corretta.']);
        }

        RateLimiter::clear($throttleKey);
        $user->forceFill(['password' => $data['password']])->save();

        // Nuova sessione: un eventuale identificativo di sessione rubato non resta valido
        $request->session()->regenerate();
        Auth::login($user);
        $audit->record('admin.password_changed', $user, [], Actor::user($user->id));

        return redirect()->route('admin.password.edit')->with('ok', 'Password aggiornata.');
    }
}
