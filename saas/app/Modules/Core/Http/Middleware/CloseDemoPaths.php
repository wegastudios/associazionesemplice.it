<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\DemoScale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nella demo (docs/DEMO.md) tutto ciò che parla con il portale, con lo staff o con Stripe risponde 404, qualunque
 * firma o sessione abbia la richiesta: amministrazione della piattaforma (/saas/admin), API dello staff e inviti
 * (/saas/…), API del portale e catalogo (/api/…), ingresso unico degli operatori, webhook di Stripe, abbonamento.
 * Unica eccezione l'azzeramento di OPcache dopo il rilascio (`saas:web:refresh`): è locale, firmato dal SaaS stesso.
 * Fuori dalla demo non fa nulla.
 */
final class CloseDemoPaths
{
    /** Percorsi chiusi nella demo. */
    public const CLOSED = ['saas', 'saas/*', 'api', 'api/*', 'accesso-unico', 'accesso-unico/*', 'area/webhook', 'area/webhook/*', 'gestionale/abbonamento'];

    /** Percorsi che restano aperti anche se dentro quelli chiusi. */
    public const OPEN = ['api/portale/v2/runtime/reset'];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (DemoScale::restricted() && $request->is(self::CLOSED) && ! $request->is(self::OPEN)) {
            abort(404);
        }

        return $next($request);
    }
}
