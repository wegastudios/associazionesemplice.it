<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Auth\DeviceSessions;
use App\Modules\Core\Auth\EmailChangeNoticeNotification;
use App\Modules\Core\Auth\EmailChangeNotification;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\QueuedMail;
use App\Modules\Core\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Il profilo di chi lavora nel gestionale: nome, email, password (endpoint di Fortify), verifica in due passaggi e
 * dispositivi collegati. Password e verifica in due passaggi restano le rotte di Fortify.
 */
final readonly class ProfileController
{
    private const EMAIL_LINK_MINUTES = 60;

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $current = $request->session()->getId();

        $sessions = DB::table('core.sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(static fn (object $s): array => [
                'current' => $s->id === $current,
                'ip' => is_string($s->ip_address) ? $s->ip_address : '',
                'device' => self::device(is_string($s->user_agent) ? $s->user_agent : ''),
                'last' => date('c', is_numeric($s->last_activity) ? (int) $s->last_activity : 0),
            ])->values()->all();

        return Inertia::render('Account/Profilo', [
            'profile' => ['name' => $user->name, 'email' => $user->email],
            'twoFactor' => $user->hasConfirmedTwoFactor(),
            'sessions' => $sessions,
        ]);
    }

    public function name(Request $request): RedirectResponse
    {
        $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);
        $user = $request->user();
        if ($user instanceof User) {
            $user->forceFill(['name' => $request->string('name')->trim()->toString()])->save();
        }

        return back()->with('status', 'Nome aggiornato.');
    }

    public function email(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'current_password:web'],
        ], ['password.current_password' => 'La password non è corretta.']);
        if ($user instanceof User) {
            $new = mb_strtolower($request->string('email')->trim()->toString());
            if ($new === mb_strtolower($user->email)) {
                throw ValidationException::withMessages(['email' => 'Questa è già la tua email.']);
            }
            // Stessa risposta se l'indirizzo è libero o già usato: chi scrive non scopre chi ha un accesso.
            if (! DB::table('core.users')->whereRaw('lower(email) = ?', [$new])->exists()) {
                $url = URL::temporarySignedRoute('profile.email.confirm', now()->addMinutes(self::EMAIL_LINK_MINUTES), ['email' => $new, 'uid' => $user->id]);
                // Subito se c'è quota, altrimenti in coda (cifrato): il link finché vale, l'avviso al vecchio indirizzo per un giorno.
                MailQueue::sendNowOrQueue(QueuedMail::notification(Notification::route('mail', $new), new EmailChangeNotification($user->name, $url), MailQueue::URGENT, now()->addMinutes(self::EMAIL_LINK_MINUTES)));
                MailQueue::sendNowOrQueue(QueuedMail::notification($user, new EmailChangeNoticeNotification(EmailChangeNoticeNotification::mask($new)), MailQueue::URGENT, now()->addDay()));
            }
        }

        return back()->with('status', 'È stato mandato un link al nuovo indirizzo: l\'email cambia solo quando lo apri. Il link vale 60 minuti.');
    }

    /** Apre il link ricevuto al nuovo indirizzo: solo la stessa persona che l'ha chiesto, solo se il link è valido e l'indirizzo è libero. */
    public function confirmEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        $new = mb_strtolower($request->string('email')->trim()->toString());
        if (! $user instanceof User || ! $request->hasValidSignature() || filter_var($new, FILTER_VALIDATE_EMAIL) === false || $request->query('uid') !== (string) $user->id) {
            return redirect()->route('profile')->withErrors(['ledger' => 'Il link non è valido o è scaduto: chiedi un nuovo cambio email dal tuo profilo.']);
        }
        if ($new !== mb_strtolower($user->email)) {
            if (DB::table('core.users')->whereRaw('lower(email) = ?', [$new])->where('id', '!=', $user->id)->exists()) {
                return redirect()->route('profile')->withErrors(['ledger' => 'Questo indirizzo non è disponibile: chiedi un nuovo cambio email dal tuo profilo.']);
            }
            $user->forceFill(['email' => $new, 'email_verified_at' => now()])->save();
        }

        return redirect()->route('profile')->with('status', 'Email aggiornata.');
    }

    /** Chiude tutte le altre sessioni della persona (altri telefoni e computer), tenendo questa. */
    public function closeOthers(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:web']], ['password.current_password' => 'La password non è corretta.']);
        $user = $request->user();
        if ($user instanceof User) {
            DeviceSessions::closeOthers($user, $request->session()->getId());
        }

        return back()->with('status', 'Gli altri dispositivi sono stati scollegati.');
    }

    private static function device(string $agent): string
    {
        $os = match (true) {
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iPhone/iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Dispositivo',
        };
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => '',
        };

        return trim($os.($browser !== '' ? ' · '.$browser : ''));
    }
}
