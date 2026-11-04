<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Org\SetupWizard;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Prima pagina del gestionale. Per ora mostra l'associazione e il numero di schede in anagrafica. */
final readonly class DashboardController
{
    public function __construct(
        private CurrentOperator $operator,
        private OrgContext $context,
        private DatabaseManager $db,
        private SetupWizard $setup,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $operator = $this->operator->get() ?? abort(403);
        $setupPending = $operator->can(Ability::ManageSettings) && $this->setup->pending();

        // Al primo accesso la configurazione guidata si apre da sola, una volta per sessione.
        if ($setupPending && ! $request->session()->has('setup_offered.'.$operator->orgId)) {
            $request->session()->put('setup_offered.'.$operator->orgId, true);

            return redirect()->route('setup');
        }

        return Inertia::render('Dashboard', [
            'setupPending' => $setupPending,
            'canSetup' => $operator->can(Ability::ManageSettings),
            // Query senza filtro: la Row-Level Security mostra solo le schede di questa associazione.
            'peopleCount' => $this->db->connection($this->context->require()->connection)
                ->table('app.people')->whereNull('deleted_at')->count(),
        ]);
    }
}
