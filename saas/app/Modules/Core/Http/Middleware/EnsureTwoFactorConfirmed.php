<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestionale: senza verifica in due passaggi confermata non si entra. Chi non l'ha ancora attivata viene
 * mandato alla pagina di attivazione; le richieste JSON ricevono 403.
 */
final class EnsureTwoFactorConfirmed
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasConfirmedTwoFactor()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Attiva la verifica in due passaggi.');
        }

        return redirect()->route('account.two-factor');
    }
}
