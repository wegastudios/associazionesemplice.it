<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Nomi dei cookie dell'app con il prefisso `__Host-`: il browser li accetta solo `Secure`, con percorso `/` e senza
 * `Domain`, e rifiuta quelli con lo stesso nome scritti da un sottodominio fratello (le associazioni stanno su
 * `<slug>.associazionesemplice.it`, come l'app) o dal dominio padre. Senza prefisso un sottodominio potrebbe sostituire il
 * cookie di un altro indirizzo (cookie tossing).
 *
 * Il prefisso si usa solo dove è valido: richiesta in HTTPS, `session.secure` acceso, percorso `/` e nessun `Domain`.
 * Altrove (sviluppo su http, prove, amministrazione sotto `/saas/admin`) il nome resta quello di prima.
 *
 * I vecchi nomi si leggono ancora fino a una data scelta (`saas.legacy_cookies`, vedi docs/COOKIE_HOST.md), poi si ignorano.
 */
final class HostCookies
{
    public const PREFIX = '__Host-';

    public const XSRF = 'XSRF-TOKEN';

    /** Cookie dell'accesso del socio: come nome senza prefisso (percorso `/area`) e come base del nome con prefisso (percorso `/`). */
    public const MEMBER = 'as_socio';

    /** Giorni di validità dell'accesso del socio: gli stessi di MemberAccess::MAX_DAYS (la prova li confronta). */
    public const MEMBER_DAYS = 90;

    public static function eligible(?Request $request): bool
    {
        return $request?->isSecure() === true
            && config('session.secure') === true
            && config('session.path') === '/'
            && config('session.domain') === null;
    }

    /** `__Host-<nome>` dove il prefisso è valido, altrimenti `<nome>`. */
    public static function name(string $base, ?Request $request): string
    {
        return self::eligible($request) ? self::PREFIX.$base : $base;
    }

    /** Nome del cookie «ricordami» della guardia: `__Host-remember_<guardia>` dove il prefisso è valido, altrimenti quello di Laravel. */
    public static function recaller(string $guard, ?Request $request): string
    {
        return self::eligible($request) ? self::PREFIX.'remember_'.$guard : self::legacyRecaller($guard);
    }

    /** Nome che Laravel dà da sé al cookie «ricordami» (impronta della classe, nessun prefisso): un sottodominio potrebbe sostituirlo. */
    public static function legacyRecaller(string $guard): string
    {
        return 'remember_'.$guard.'_'.sha1(SessionGuard::class);
    }

    /** Fine del periodo in cui si leggono ancora i vecchi nomi (`remember` o `member`); senza data (o non valida), non si leggono più. */
    public static function legacyUntil(string $which): ?CarbonImmutable
    {
        $value = config('saas.legacy_cookies.'.$which.'_until');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    public static function legacyOpen(string $which): bool
    {
        return self::legacyUntil($which)?->isFuture() === true;
    }
}
