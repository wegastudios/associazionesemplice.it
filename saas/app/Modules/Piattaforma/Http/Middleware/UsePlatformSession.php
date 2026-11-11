<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sotto il percorso dell'amministrazione la sessione è un'altra: cookie con nome e percorso propri (non lo ricevono né
 * il gestionale né i sottodomini dei soci né il portale), guardia propria, scadenza breve. Va prima di ogni
 * middleware che apre la sessione.
 */
final class UsePlatformSession
{
    public const GUARD = 'platform';

    /** Percorso dell'amministrazione, senza barre ai lati (`admin`, in produzione dietro `/saas/admin`). */
    public static function path(): string
    {
        $path = config('saas.admin_path', 'admin');

        return trim(is_string($path) ? $path : 'admin', '/');
    }

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $path = self::path();
        if ($request->is($path) || $request->is($path.'/*')) {
            config([
                'session.cookie' => app()->isProduction() ? '__Secure-as_admin' : 'as_admin',
                'session.path' => '/'.$path,
                'session.expire_on_close' => true,
                'session.lifetime' => 120,
                'session.same_site' => 'strict',
            ]);
            Auth::shouldUse(self::GUARD);
        }

        return $next($request);
    }
}
