<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\DatabaseManager;
use Inertia\Inertia;
use Inertia\Response;

/** Prima pagina del gestionale. Per ora mostra l'associazione e il numero di schede in anagrafica. */
final readonly class DashboardController
{
    public function __construct(
        private CurrentOperator $operator,
        private OrgContext $context,
        private DatabaseManager $db,
    ) {}

    public function __invoke(): Response
    {
        $this->operator->get() ?? abort(403);

        return Inertia::render('Dashboard', [
            // Query senza filtro: la Row-Level Security mostra solo le schede di questa associazione.
            'peopleCount' => $this->db->connection($this->context->require()->connection)
                ->table('app.people')->whereNull('deleted_at')->count(),
        ]);
    }
}
