<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Middleware;

use App\Modules\Piattaforma\Models\PlatformStaff;
use App\Modules\Portale\Contracts\StaffAccess;
use App\Modules\Portale\Contracts\StaffAccessDenied;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accesso all'amministrazione: staff attivo, entrato con la verifica in due passaggi da meno di 8 ore. Con `write`
 * serve anche il ruolo che può modificare (amministratore o «vendita»; il «supporto» è sola lettura).
 *
 * Una sessione aperta dal portale (accesso unico) ha regole in più (docs/UTENTI_E_ACCESSI.md §5): inattività massima
 * 2 ore, durata massima 8, e ogni 5 minuti si chiede al portale se la sessione centrale vale ancora e se il livello è
 * lo stesso; se non vale o è cambiato, la sessione si chiude. Se il portale non risponde, due controlli falliti di fila
 * (al massimo 10 minuti) chiudono la sessione: nessun accesso senza il portale.
 */
final class RequirePlatformStaff
{
    public const MFA_VALID_SECONDS = 8 * 3600;

    public const LOCAL_IDLE_SECONDS = 3600;

    public const SSO_IDLE_SECONDS = 2 * 3600;

    public const SSO_MAX_SECONDS = 8 * 3600;

    public const SSO_CHECK_EVERY = 300;

    public const SSO_MAX_FAILED_CHECKS = 2;

    public function __construct(private readonly StaffAccess $access) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $mode = 'read'): Response
    {
        $guard = Auth::guard(UsePlatformSession::GUARD);
        $staff = $guard->user();
        $mfaAt = $request->session()->get('platform.mfa_at');

        // Chi non è entrato (magari è a metà dell'accesso) viene solo rimandato; chi era dentro e non ha più i
        // requisiti (disattivato, verifica scaduta, sessione scaduta o revocata) perde la sessione.
        if (! $staff instanceof PlatformStaff) {
            return $this->toLogin();
        }
        if ($staff->disabled_at !== null || ! is_int($mfaAt) || now()->getTimestamp() - $mfaAt > self::MFA_VALID_SECONDS) {
            return $this->close($request);
        }

        $now = now()->getTimestamp();
        $last = $request->session()->get('platform.last');
        $sso = $request->session()->get('platform.sso');
        $idle = is_array($sso) ? self::SSO_IDLE_SECONDS : self::LOCAL_IDLE_SECONDS;
        if (is_int($last) && $now - $last > $idle) {
            return $this->close($request);
        }

        if (is_array($sso) && ! $this->ssoStillValid($request, $staff, $sso, $now)) {
            return $this->close($request);
        }
        $request->session()->put('platform.last', $now);

        abort_if($mode === 'write' && ! $staff->canWrite(), 403, 'Il tuo ruolo è di sola lettura.');

        return $next($request);
    }

    /** @param  array<mixed>  $sso */
    private function ssoStillValid(Request $request, PlatformStaff $staff, array $sso, int $now): bool
    {
        $sid = $sso['sid'] ?? null;
        $level = $sso['level'] ?? null;
        $started = $sso['started'] ?? null;
        $checked = $sso['checked'] ?? null;
        if (! is_string($sid) || ! is_string($level) || ! is_int($started) || ! is_int($checked)) {
            return false;
        }
        if ($now - $started > self::SSO_MAX_SECONDS) {
            return false;
        }
        if ($now - $checked < self::SSO_CHECK_EVERY) {
            return true;
        }

        $fails = is_int($sso['fails'] ?? null) ? $sso['fails'] : 0;
        try {
            $current = $this->access->check($sid);
        } catch (StaffAccessDenied) {
            // Portale che non risponde: due controlli falliti di fila (al massimo 10 minuti) e la sessione si chiude.
            if ($fails + 1 >= self::SSO_MAX_FAILED_CHECKS) {
                return false;
            }
            $request->session()->put('platform.sso', ['checked' => $now, 'fails' => $fails + 1] + $sso);

            return true;
        }
        // Revocata, o livello cambiato: la sessione si chiude e si rientra dal portale con il livello nuovo.
        if ($current === null || $current !== $level || $staff->level() !== $level) {
            return false;
        }
        $request->session()->put('platform.sso', ['checked' => $now, 'fails' => 0] + $sso);

        return true;
    }

    private function close(Request $request): Response
    {
        Auth::guard(UsePlatformSession::GUARD)->logout();
        $request->session()->invalidate();

        return $this->toLogin();
    }

    private function toLogin(): Response
    {
        // Con l'accesso unico acceso si rientra dal portale (solo verso l'area SaaS); altrimenti dall'accesso locale.
        return $this->access->enabled() ? redirect()->away($this->access->loginUrl()) : redirect('/'.UsePlatformSession::path().'/accesso');
    }
}
