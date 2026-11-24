<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
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
            'session.cookie' => self::cookieName(),
        ]);

        self::rebind();

        try {
            return $next($request);
        } finally {
            config(['session' => $before]);
            self::rebind();
        }
    }

    /**
     * Con il prefisso __Host- il browser accetta il cookie solo Secure, con percorso «/» e senza Domain, e rifiuta quello
     * con lo stesso nome scritto da un sottodominio fratello o dal dominio padre (.associazionesemplice.it). Vale
     * ovunque ci sia HTTPS (produzione, staging, demo); in locale e nelle prove, che girano anche su http, no.
     */
    public static function cookieName(): string
    {
        return config('session.secure') === true && config('session.path') === '/' && ! app()->environment(['local', 'testing']) ? '__Host-as_pubblico' : 'as_pubblico';
    }

    /**
     * `session.store` e il reindirizzatore (che ci scrive i messaggi dei `->with()`) ricordano il driver scelto alla
     * prima volta che qualcuno li usa: se è successo prima di questo middleware, i messaggi andrebbero in un'altra
     * sessione e si perderebbero. Si rifanno con il driver di adesso.
     */
    private static function rebind(): void
    {
        $app = app();
        // Il cookie dei dati della sessione (quello che il driver «cookie» mette da sé) prende percorso, dominio e Secure
        // da qui: devono essere quelli di adesso, cioè mai un Domain.
        $jar = $app->make('cookie');
        if ($jar instanceof CookieJar) {
            $path = config('session.path');
            $sameSite = config('session.same_site');
            $jar->setDefaultPathAndDomain(is_string($path) ? $path : '/', is_string(config('session.domain')) ? config('session.domain') : null, config('session.secure') === true, is_string($sameSite) ? $sameSite : null);
        }
        $app->forgetInstance('session.store');
        $redirector = $app->make('redirect');
        if ($redirector instanceof Redirector) {
            $redirector->setSession($app->make('session.store'));
        }
    }
}
