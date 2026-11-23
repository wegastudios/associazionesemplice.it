<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagine lunghe ma legittime (copie di sicurezza, importazioni, libro soci, esportazioni, verifica delle ricevute,
 * chiusura dell'anno, archivio): i tempi massimi delle operazioni lunghe (config saas.database_timeouts.long) al posto
 * di quelli delle richieste. Nelle rotte: ->middleware('db.long').
 */
final readonly class LongDatabaseTimeouts
{
    public function __construct(private OrgContext $context) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->context->withLongTimeouts(static fn (): Response => $next($request));
    }
}
