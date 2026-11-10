<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Middleware;

use App\Modules\Piattaforma\Models\PlatformStaff;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accesso all'amministrazione: staff attivo, entrato con la verifica in due passaggi da meno di 8 ore. Con `write`
 * serve anche il ruolo amministratore (il «solo lettura» non scrive mai).
 */
final class RequirePlatformStaff
{
    public const MFA_VALID_SECONDS = 8 * 3600;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $mode = 'read'): Response
    {
        $guard = Auth::guard(UsePlatformSession::GUARD);
        $staff = $guard->user();
        $mfaAt = $request->session()->get('platform.mfa_at');

        $login = '/'.UsePlatformSession::path().'/accesso';

        // Chi non è entrato (magari è a metà dell'accesso) viene solo rimandato; chi era dentro e non ha più i
        // requisiti (disattivato, verifica scaduta) perde la sessione.
        if (! $staff instanceof PlatformStaff) {
            return redirect($login);
        }
        if ($staff->disabled_at !== null || ! is_int($mfaAt) || time() - $mfaAt > self::MFA_VALID_SECONDS) {
            $guard->logout();
            $request->session()->invalidate();

            return redirect($login);
        }

        abort_if($mode === 'write' && ! $staff->canWrite(), 403, 'Il tuo ruolo è di sola lettura.');

        return $next($request);
    }
}
