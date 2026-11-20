<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limiti di frequenza sulle rotte di Fortify che non ne hanno: «password dimenticata», conferma della password e
 * cambio password. Sta nel gruppo web e riconosce la richiesta da metodo e percorso, così regge anche con la cache
 * delle rotte (le rotte di Fortify non si possono modificare dopo l'avvio). I limiti sono in AuthServiceProvider.
 */
final class ThrottleAccountSecurity
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $limiter = $this->limiter($request);

        return $limiter === null ? $next($request) : $this->throttle->handle($request, $next, $limiter);
    }

    private function limiter(Request $request): ?string
    {
        if ($request->isMethod('POST') && $request->is(self::path('email'))) {
            return 'password-reset';
        }
        if ($request->isMethod('POST') && $request->is(self::path('confirm'))) {
            return 'password-confirm';
        }
        if ($request->isMethod('PUT') && $request->is('user/password')) {
            return 'password-confirm';
        }

        return null;
    }

    private static function path(string $key): string
    {
        $path = config('fortify.paths.password.'.$key);

        return trim(is_string($path) ? $path : '', '/');
    }
}
