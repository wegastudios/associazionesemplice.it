<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sessione nel cookie, invece che nel database, per le pagine che non hanno un accesso da proteggere: area soci
 * (l'accesso del socio sta nel suo cookie `as_socio` e nella tabella dei suoi accessi, non qui) e moduli pubblici di
 * donazione e adesione. Prima ogni visita, anche di un robot, scriveva una riga in `core.sessions`.
 *
 * Vale solo per le rotte che portano il marchio «cookie-session» (mai per quelle con `auth`: gli operatori restano nel
 * database, che serve a «Dispositivi collegati», alla revoca e ai limiti di durata). Va prima di StartSession, che
 * sceglie il «driver» al primo uso. Il cookie ha un nome proprio, così non tocca quello del gestionale sullo stesso
 * indirizzo. Dentro ci stanno solo messaggi di una pagina, la scheda scelta e il token CSRF; il cookie è cifrato dal
 * middleware dei cookie (niente cifratura doppia).
 *
 * Si applica solo se il driver impostato è «database»: con altri driver (prove, sviluppo con «array») non cambia nulla.
 * A risposta fatta i valori tornano come prima, così una richiesta non lascia impostazioni a quella dopo.
 */
final class UseCookieSession
{
    /** Marchio da mettere alle rotte, come middleware. */
    public const MARKER = 'cookie-session';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if (! $route instanceof Route || config('session.driver') !== 'database' || ! in_array(self::MARKER, $route->gatherMiddleware(), true)) {
            return $next($request);
        }

        $before = config('session');
        config([
            'session.driver' => 'cookie',
            'session.encrypt' => false,
            'session.domain' => null,
            'session.cookie' => app()->isProduction() ? '__Host-as_pubblico' : 'as_pubblico',
        ]);

        try {
            return $next($request);
        } finally {
            config(['session' => $before]);
        }
    }
}
