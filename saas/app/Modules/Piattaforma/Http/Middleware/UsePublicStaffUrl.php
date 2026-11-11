<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le pagine dell'amministrazione e dello staff (/saas/admin, /saas/staff) stanno sotto l'indirizzo del portale
 * (https://associazionesemplice.it/saas/…) dietro il suo proxy: gli indirizzi che il SaaS scrive (moduli, rinvii) devono
 * essere quelli. L'indirizzo è fisso (SAAS_PUBLIC_URL), mai letto dalle intestazioni della richiesta: nessuno può
 * spostare i link con un X-Forwarded-Host inventato. Vuoto = come prima (l'indirizzo dell'app).
 */
final class UsePublicStaffUrl
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $public = config('saas.public_url');
        if (is_string($public) && $public !== '' && ($request->is(UsePlatformSession::path()) || $request->is(UsePlatformSession::path().'/*') || $request->is('saas/staff/*'))) {
            URL::forceRootUrl(rtrim($public, '/'));
            URL::forceScheme(parse_url($public, PHP_URL_SCHEME) === 'http' ? 'http' : 'https');
        }

        return $next($request);
    }
}
