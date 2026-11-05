<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un utente disattivato dall'amministrazione della piattaforma non può usare nessuna sessione: la richiesta chiude la
 * sessione e rimanda all'accesso. Vale anche per chi ha già effettuato l'accesso al momento della disattivazione.
 */
final class EnsureUserActive
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->disabled_at === null) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(403, 'Utente disattivato.');
        }

        return redirect('/accesso')->withErrors(['email' => 'Questo utente è disattivato: contatta l\'amministrazione.']);
    }
}
