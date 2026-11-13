<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Models\User;
use App\Modules\Portale\Contracts\LifecyclePolicy;
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
        private AuditLog $audit,
        private LifecyclePolicy $lifecycle,
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
        $opened = false;

        if ($operator === null) {
            $request->session()->forget(self::SESSION_KEY);
            $all = $this->memberships->forUser($user->id);

            // Solo associazioni sospese: nessun dato, nessuna esportazione, solo la pagina del pagamento.
            if ($all === [] && $this->memberships->suspendedNames($user->id) !== []) {
                return $request->expectsJson()
                    ? abort(402, 'L\'abbonamento è scaduto: riattivalo dal portale.')
                    : redirect()->route('org.suspended');
            }

            if (count($all) !== 1) {
                return $request->expectsJson()
                    ? abort(409, 'Scegli l\'associazione.')
                    : redirect()->route('orgs.choose');
            }

            $operator = $all[0];
            $request->session()->put(self::SESSION_KEY, $operator->orgId);
            $opened = true;
        }

        $this->context->enter($operator->orgId);
        $this->operator->set($operator);

        // Prova finita senza un piano: si consulta, non si modifica (il profilo personale resta modificabile).
        if (! $request->isMethodSafe() && ! $request->is('gestionale/profilo*') && $this->lifecycle->readOnly($operator->orgId)) {
            $this->operator->set(null);
            abort(403, 'La prova è finita: puoi consultare i dati ma non modificarli. Scegli un piano dal portale per tornare a lavorare.');
        }

        // Si annota quando un operatore apre l'associazione, non ogni pagina.
        if ($opened || $request->session()->pull('operator_org_opened') === true) {
            $this->audit->record('org.opened', null, null, ['role' => $operator->role->value]);
        }

        try {
            return $next($request);
        } finally {
            $this->operator->set(null);
        }
    }
}
