<?php

declare(strict_types=1);

namespace App\Modules\Portale\Http\Middleware;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Contracts\LifecyclePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Esportazioni: non durante la prova se il catalogo la esclude (terms.trial.exports). Uso: `exports.allowed`. */
final readonly class RequireFullAccount
{
    public function __construct(
        private OrgContext $context,
        private LifecyclePolicy $policy,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->policy->exportsAllowed($this->context->require()->id)) {
            abort(403, LifecyclePolicy::CHOOSE_A_PLAN);
        }

        return $next($request);
    }
}
