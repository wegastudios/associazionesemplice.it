<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\HostCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Protezione CSRF di Laravel con un solo cambio: il cookie con il token per JavaScript si chiama `__Host-XSRF-TOKEN`
 * dove il prefisso è valido (HostCookies), invece di `XSRF-TOKEN`. La lettura non cambia: il token arriva
 * nell'intestazione `X-XSRF-TOKEN` (il nome del cookie lo sceglie il browser lato client, vedi resources/js/lib/xsrf.js)
 * e si confronta con quello della sessione. Il cookie non decide mai nulla da solo.
 */
final class HostPreventRequestForgery extends PreventRequestForgery
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  array<string, mixed>  $config
     */
    protected function newCookie($request, $config): Cookie
    {
        $cookie = parent::newCookie($request, $config);

        return Cookie::create(
            HostCookies::name(HostCookies::XSRF, $request),
            $cookie->getValue(),
            $cookie->getExpiresTime(),
            $cookie->getPath(),
            $cookie->getDomain(),
            $cookie->isSecure(),
            $cookie->isHttpOnly(),
            $cookie->isRaw(),
            $cookie->getSameSite(),
            $cookie->isPartitioned(),
        );
    }
}
