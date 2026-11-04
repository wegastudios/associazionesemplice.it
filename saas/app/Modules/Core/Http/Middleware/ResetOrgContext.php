<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prima middleware di ogni richiesta: parte sempre senza associazione e la azzera alla fine, così una
 * connessione riusata non porta mai con sé l'associazione della richiesta precedente.
 * L'associazione la imposta poi chi riconosce l'utente o il dominio.
 */
final readonly class ResetOrgContext
{
    public function __construct(private OrgContext $context) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $this->context->leave();

        try {
            return $next($request);
        } finally {
            $this->context->leave();
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->leave();
    }
}
