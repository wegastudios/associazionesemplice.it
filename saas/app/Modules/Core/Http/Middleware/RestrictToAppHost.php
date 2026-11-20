<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accesso, gestionale, amministrazione e API rispondono solo sull'indirizzo dell'applicazione (APP_URL) e su quello
 * pubblico del portale (SAAS_PUBLIC_URL). Sugli altri indirizzi, cioè i sottodomini e i domini dei soci, restano
 * raggiungibili soltanto l'area soci e i moduli pubblici: tutto il resto risponde 404.
 */
final class RestrictToAppHost
{
    /** Percorsi pubblici che hanno senso anche sugli indirizzi dei soci. */
    private const MEMBER_PATHS = ['area', 'area/*', 'dona/*', 'adesione/*', 'up', 'build/*', 'favicon.ico', 'favicon.svg', 'robots.txt'];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isAppHost($request->getHost()) && ! $request->is(self::MEMBER_PATHS)) {
            abort(404);
        }

        return $next($request);
    }

    private function isAppHost(string $host): bool
    {
        $host = strtolower($host);
        foreach (['app.url', 'saas.public_url'] as $key) {
            $url = config($key);
            $own = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_HOST) : null;
            if (is_string($own) && strtolower($own) === $host) {
                return true;
            }
        }

        return false;
    }
}
