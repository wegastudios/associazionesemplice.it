<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dietro il proxy del portale l'indirizzo del visitatore arriva in `X-Forwarded-For`, ma solo se la richiesta porta il
 * segreto condiviso (SAAS_PROXY_SECRET) in `X-Saas-Proxy-Secret`, confrontato a tempo costante. Senza segreto valido le
 * intestazioni di inoltro si ignorano: un altro sito dello stesso server non può inventarsi un indirizzo.
 */
final class ResolveProxyClient
{
    public const HEADER = 'X-Saas-Proxy-Secret';

    /** Lunghezza minima del segreto: più corto non vale come segreto. */
    public const MIN_LENGTH = 32;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('saas.proxy_secret');
        $given = $request->headers->get(self::HEADER);
        if (is_string($secret) && strlen($secret) >= self::MIN_LENGTH && is_string($given) && hash_equals($secret, $given)) {
            $ip = trim(explode(',', (string) $request->headers->get('X-Forwarded-For'))[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $request->server->set('REMOTE_ADDR', $ip);
            }
        }

        return $next($request);
    }
}
