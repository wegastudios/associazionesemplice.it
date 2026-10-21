<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MailSettings;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Recupero della password dell'amministratore tramite link ricevuto per email. */
class ResetPasswordController extends Controller
{
    public function request(): View
    {
        return view('admin.forgot');
    }

    public function send(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email']);

        // Risposta sempre uguale: non si scopre chi ha un account. Il link parte solo per gli amministratori.
        $user = User::query()->where('email', $data['email'])->first();
        if ($user !== null && $user->isAdmin() && ! $user->sso_managed) {
            app(MailSettings::class)->apply();
            PasswordBroker::sendResetLink(['email' => $user->email]);
            $audit->record('admin.password_reset_requested', $user);
        }

        return back()->with('ok', 'Se l\'indirizzo corrisponde a un amministratore, riceverai un\'email con il link per scegliere una nuova password (vale 60 minuti).');
    }

    public function form(Request $request, string $token): View
    {
        return view('admin.reset', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers()],
        ], [
            'password.confirmed' => 'Le due password non coincidono.',
            'password.min' => 'La password deve avere almeno 12 caratteri.',
            'password.letters' => 'La password deve contenere almeno una lettera.',
            'password.numbers' => 'La password deve contenere almeno un numero.',
        ]);

        $status = PasswordBroker::reset($data, function (User $user, string $password) use ($audit) {
            abort_unless($user->isAdmin() && ! $user->sso_managed, 403);
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
            $audit->record('admin.password_reset', $user);
        });

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Il link non è valido o è scaduto: richiedine uno nuovo.']);
        }

        return redirect()->route('admin.login')->with('ok', 'Password aggiornata: ora puoi accedere.');
    }
}
