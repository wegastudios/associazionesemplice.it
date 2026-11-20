<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limiti della sessione del gestionale (la guardia «web»: l'amministrazione della piattaforma ha la sua):
 * - durata massima assoluta dal momento dell'accesso, non dall'ultima attività; allo scadere si esce;
 * - rientro con «ricordami»: chi ha la verifica in due passaggi attiva deve rifare l'accesso con il codice.
 * Una sessione già aperta prima di questo controllo non ha l'orario dell'accesso: la prima richiesta lo scrive e
 * nessuno viene mandato fuori all'aggiornamento.
 */
final class EnforceSessionLimits
{
    public const STARTED_AT = 'auth.started_at';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::getDefaultDriver() !== 'web') {
            return $next($request);
        }

        $guard = Auth::guard('web');
        $user = $guard->user();
        if (! $user instanceof User || ! $guard instanceof SessionGuard) {
            return $next($request);
        }

        // Sessione nuova nata dal cookie «ricordami»: con il secondo fattore si ripassa dall'accesso. Il cookie si
        // toglie senza cambiare il token, così gli altri dispositivi non perdono il loro.
        if ($guard->viaRemember() && $user->hasConfirmedTwoFactor()) {
            Cookie::queue(Cookie::forget($guard->getRecallerName()));

            return $this->leave($request, 'Per sicurezza accedi di nuovo con la password e il codice.');
        }

        $startedAt = $request->session()->get(self::STARTED_AT);
        $now = now()->getTimestamp();
        if (! is_int($startedAt)) {
            $request->session()->put(self::STARTED_AT, $now);

            return $next($request);
        }

        $maxMinutes = config('saas.auth.session_max_minutes');
        if (is_int($maxMinutes) && $maxMinutes > 0 && $now - $startedAt >= $maxMinutes * 60) {
            $guard->logout();

            return $this->leave($request, 'La sessione è scaduta: accedi di nuovo.');
        }

        return $next($request);
    }

    private function leave(Request $request, string $message): Response
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }
        if ($request->header('X-Inertia') !== null) {
            return Inertia::location('/accesso');
        }

        return redirect('/accesso')->withErrors(['email' => $message]);
    }
}
