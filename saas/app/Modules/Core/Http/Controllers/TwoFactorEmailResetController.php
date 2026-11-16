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

/**
 * Chi ha perso il telefono e i codici di recupero toglie da sé la verifica in due passaggi: si parte dalla schermata del
 * codice (quindi con la password già giusta), arriva un link all'indirizzo dell'account, valido 60 minuti e una volta
 * sola. Aprendolo la verifica si toglie; al prossimo accesso va riattivata subito, col telefono nuovo.
 */
final readonly class TwoFactorEmailResetController
{
    private const LINK_MINUTES = 60;

    private const MAX_REQUESTS = 3;

    public function __construct(private SecurityLog $log, private DemoEnvironment $demo) {}

    public function request(Request $request): RedirectResponse
    {
        $id = $request->session()->get('login.id');
        $user = is_string($id) || is_int($id) ? User::query()->find($id) : null;
        // La stessa risposta in ogni caso: niente si capisce da fuori.
        $reply = back()->with('status', 'Se serve, ti abbiamo scritto all\'indirizzo del tuo account: apri il link entro 60 minuti.');
        if (! $user instanceof User || ! $user->hasConfirmedTwoFactor() || $this->demo->enabled()) {
            return $reply;
        }

        $key = '2fa-reset-mail|'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS)) {
            return $reply;
        }
        RateLimiter::hit($key, 3600);

        $nonce = Str::random(40);
        Cache::put('2fa-reset:'.hash('sha256', $nonce), (string) $user->id, now()->addMinutes(self::LINK_MINUTES));
        $url = URL::temporarySignedRoute('two-factor.email-reset.confirm', now()->addMinutes(self::LINK_MINUTES), ['uid' => $user->id, 'n' => $nonce]);
        $user->notify(new TwoFactorEmailResetNotification($user->name, $url));
        $this->log->record('two_factor.reset_requested', (string) $user->id);

        return $reply;
    }

    public function confirm(Request $request): RedirectResponse
    {
        $nonce = $request->query('n');
        $uid = $request->query('uid');
        $user = is_string($uid) ? User::query()->find($uid) : null;
        $stored = is_string($nonce) ? Cache::pull('2fa-reset:'.hash('sha256', $nonce)) : null;
        if (! $request->hasValidSignature() || ! $user instanceof User || $stored !== (string) $user->id) {
            return redirect()->route('login')->withErrors(['email' => 'Il link non è valido o è scaduto: dalla schermata del codice chiedine uno nuovo.']);
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->log->record('two_factor.reset_by_email', (string) $user->id);
        $user->notify(new TwoFactorResetNoticeNotification($user->name));

        return redirect()->route('login')->with('status', 'La verifica in due passaggi è stata tolta. Accedi con la password e riattivala subito con il telefono nuovo.');
    }
}
