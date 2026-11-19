<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Audit\Contracts\SecurityLog;
use App\Modules\Core\Auth\TwoFactorEmailResetNotification;
use App\Modules\Core\Auth\TwoFactorResetNoticeNotification;
use App\Modules\Core\Demo\DemoEnvironment;
use App\Modules\Core\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chi ha perso il telefono e i codici di recupero toglie da sé la verifica in due passaggi: si parte dalla schermata del
 * codice (quindi con la password già giusta), arriva un link all'indirizzo dell'account, valido 15 minuti e una volta
 * sola; al massimo 2 richieste al giorno e 6 al mese per utente (oltre, lo sblocca il supporto) e 5 all'ora per indirizzo. Il link apre una pagina col pulsante «Togli la verifica»: la verifica si toglie solo col pulsante (POST); al prossimo accesso va riattivata subito, col telefono nuovo.
 */
final readonly class TwoFactorEmailResetController
{
    private const LINK_MINUTES = 15;

    /** Richieste per utente: al massimo 2 al giorno e 6 al mese; oltre, lo sblocco lo fa il supporto. */
    private const MAX_PER_DAY = 2;

    private const MAX_PER_MONTH = 6;

    public const KEYS = ['2fa-reset-mail-day|', '2fa-reset-mail-month|'];

    public function __construct(private SecurityLog $log, private DemoEnvironment $demo) {}

    public function request(Request $request): RedirectResponse
    {
        $id = $request->session()->get('login.id');
        $user = is_string($id) || is_int($id) ? User::query()->find($id) : null;
        // La stessa risposta in ogni caso: niente si capisce da fuori.
        $reply = back()->with('status', 'Se serve, è partita un\'email all\'indirizzo del tuo account: apri il link entro 15 minuti.');
        if (! $user instanceof User || ! $user->hasConfirmedTwoFactor() || $this->demo->enabled()) {
            return $reply;
        }

        // Chi è qui ha già la password giusta: può leggere che il limite è finito.
        [$day, $month] = [self::KEYS[0].$user->id, self::KEYS[1].$user->id];
        if (RateLimiter::tooManyAttempts($day, self::MAX_PER_DAY) || RateLimiter::tooManyAttempts($month, self::MAX_PER_MONTH)) {
            return back()->withErrors(['reset' => 'Hai già chiesto troppi link: scrivi al supporto di Associazione Semplice per sbloccare l\'accesso.']);
        }
        RateLimiter::hit($day, 86_400);
        RateLimiter::hit($month, 30 * 86_400);

        $nonce = Str::random(40);
        Cache::put('2fa-reset:'.hash('sha256', $nonce), (string) $user->id, now()->addMinutes(self::LINK_MINUTES));
        $url = URL::temporarySignedRoute('two-factor.email-reset.confirm', now()->addMinutes(self::LINK_MINUTES), ['uid' => $user->id, 'n' => $nonce]);
        $user->notify(new TwoFactorEmailResetNotification($user->name, $url));
        $this->log->record('two_factor.reset_requested', (string) $user->id);

        return $reply;
    }

    /**
     * Aprire il link non toglie niente: i filtri della posta (Safe Links, antivirus) lo aprono da soli. Si mostra solo la
     * pagina col pulsante; il codice monouso si consuma nel POST.
     */
    public function confirm(Request $request): Response|RedirectResponse
    {
        $nonce = $request->query('n');
        $uid = $request->query('uid');
        $user = is_string($uid) ? User::query()->find($uid) : null;
        if (! $request->hasValidSignature() || ! $user instanceof User || ! is_string($nonce) || Cache::get('2fa-reset:'.hash('sha256', $nonce)) !== (string) $user->id) {
            return $this->invalid();
        }

        return Inertia::render('Auth/TwoFactorEmailReset', ['action' => $request->fullUrl()]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $nonce = $request->query('n');
        $uid = $request->query('uid');
        $user = is_string($uid) ? User::query()->find($uid) : null;
        // Il codice si consuma solo se la firma è valida: un link manomesso non brucia quello vero.
        $stored = $request->hasValidSignature() && is_string($nonce) ? Cache::pull('2fa-reset:'.hash('sha256', $nonce)) : null;
        if (! $user instanceof User || $stored !== (string) $user->id) {
            return $this->invalid();
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->log->record('two_factor.reset_by_email', (string) $user->id);
        $user->notify(new TwoFactorResetNoticeNotification($user->name));

        return redirect()->route('login')->with('status', 'La verifica in due passaggi è stata tolta. Accedi con la password e riattivala subito con il telefono nuovo.');
    }

    private function invalid(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => 'Il link non è valido o è scaduto: dalla schermata del codice chiedine uno nuovo.']);
    }
}
