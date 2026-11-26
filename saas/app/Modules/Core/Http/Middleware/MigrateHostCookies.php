<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\HostCookies;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie as CookieJar;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passaggio ai nomi con il prefisso `__Host-` (docs/COOKIE_HOST.md) senza far uscire nessuno: per un periodo scelto
 * (`saas.legacy_cookies`) i vecchi cookie «ricordami» e `as_socio` si leggono ancora; alla prima visita il valore passa
 * sotto il nome nuovo e quello vecchio si cancella. Il codice legge e scrive solo i nomi nuovi: il vecchio nome non è mai
 * più scritto da noi. Chiusa la finestra, il vecchio cookie si cancella e basta (chi lo aveva rifà l'accesso una volta).
 *
 * Va dopo la cifratura dei cookie (il valore è già in chiaro) e prima di tutto ciò che legge l'utente. Dove il prefisso
 * non è valido (http, sviluppo, prove, /saas/admin) non fa nulla.
 */
final class MigrateHostCookies
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (HostCookies::eligible($request)) {
            $this->remember($request);
            $this->member($request);
        }

        return $next($request);
    }

    private function remember(Request $request): void
    {
        $legacy = HostCookies::legacyRecaller('web');
        $value = $request->cookies->get($legacy);
        if (! is_string($value) || $value === '') {
            return;
        }
        $new = HostCookies::recaller('web', $request);
        $until = HostCookies::legacyUntil('remember');
        if (! $request->cookies->has($new) && $until !== null && $until->isFuture()) {
            // Scade quando sarebbe scaduto il vecchio (al più tardi alla fine della finestra): il passaggio non allunga il «ricordami».
            $request->cookies->set($new, $value);
            CookieJar::queue(Cookie::create($new, $value, $until->getTimestamp(), '/', null, true, true, false, Cookie::SAMESITE_LAX));
        }
        CookieJar::queue(Cookie::create($legacy, null, 1, '/', null, true, true, false, Cookie::SAMESITE_LAX));
    }

    private function member(Request $request): void
    {
        $value = $request->cookies->get(HostCookies::MEMBER);
        if (! is_string($value) || $value === '') {
            return;
        }
        $new = HostCookies::PREFIX.HostCookies::MEMBER;
        if (! $request->cookies->has($new) && HostCookies::legacyOpen('member')) {
            // La durata vera la decide il database dei soci (accesso a 90 giorni, 30 senza uso): il cookie fa solo da contenitore.
            $request->cookies->set($new, $value);
            CookieJar::queue(Cookie::create($new, $value, now()->addDays(HostCookies::MEMBER_DAYS), '/', null, true, true, false, Cookie::SAMESITE_LAX));
        }
        CookieJar::queue(Cookie::create(HostCookies::MEMBER, null, 1, '/area', null, true, true, false, Cookie::SAMESITE_LAX));
    }
}
