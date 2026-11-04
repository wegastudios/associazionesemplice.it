<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestionale: individua l'associazione dell'operatore e la imposta (OrgContext). L'associazione scelta sta in
 * sessione, ma a ogni richiesta si ricontrolla che l'utente ne sia ancora operatore e che sia attiva: un ruolo
 * tolto vale subito, senza aspettare la fine della sessione.
 */
final readonly class IdentifyOperatorOrg
{
    public const SESSION_KEY = 'operator_org_id';

    public function __construct(
        private Memberships $memberships,
        private OrgContext $context,
        private CurrentOperator $operator,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $chosen = $request->session()->get(self::SESSION_KEY);
        $operator = is_string($chosen) ? $this->memberships->find($user->id, $chosen) : null;

        if ($operator === null) {
            $request->session()->forget(self::SESSION_KEY);
            $all = $this->memberships->forUser($user->id);

            if (count($all) !== 1) {
                return $request->expectsJson()
                    ? abort(409, 'Scegli l\'associazione.')
                    : redirect()->route('orgs.choose');
            }

            $operator = $all[0];
            $request->session()->put(self::SESSION_KEY, $operator->orgId);
        }

        $this->context->enter($operator->orgId);
        $this->operator->set($operator);

        try {
            return $next($request);
        } finally {
            $this->operator->set(null);
        }
    }
}
