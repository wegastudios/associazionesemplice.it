<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\HomeItem;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Org\SetupWizard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Prima pagina del gestionale: cosa c'è da fare, cosa succede oggi e i saldi, raccolti dai moduli (HomeWidget). */
final readonly class DashboardController
{
    public function __construct(
        private CurrentOperator $operator,
        private OrgContext $context,
        private DatabaseManager $db,
        private SetupWizard $setup,
        private Container $app,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $operator = $this->operator->get() ?? abort(403);
        $setupPending = $operator->can(Ability::ManageSettings) && $this->setup->pending();

        // Anno sociale e anno fiscale si scelgono esplicitamente come prima cosa: finché non è fatto, si torna qui ogni volta.
        if ($operator->can(Ability::ManageSettings) && $this->setup->mustChoose($operator)) {
            return redirect()->route('setup');
        }

        // Al primo accesso la configurazione guidata si apre da sola, una volta per sessione.
        if ($setupPending && ! $request->session()->has('setup_offered.'.$operator->orgId)) {
            $request->session()->put('setup_offered.'.$operator->orgId, true);

            return redirect()->route('setup');
        }

        // Riquadri e contatori arrivano subito dopo la pagina, con una seconda richiesta (gruppo «cruscotto»): la pagina si
        // apre senza aspettarli, e i numeri si calcolano a ogni apertura come prima, mai da una copia.
        /** @var list<HomeItem>|null $items */
        $items = null;
        $in = function (string $section) use (&$items, $operator): array {
            if ($items === null) {
                $items = [];
                foreach ($this->app->tagged(HomeWidget::TAG) as $widget) {
                    if ($widget instanceof HomeWidget) {
                        array_push($items, ...$widget->items($operator));
                    }
                }
            }

            return array_values(array_filter($items, static fn (HomeItem $i): bool => $i->section === $section));
        };
        $toArray = static fn (HomeItem $i): array => $i->toArray();

        return Inertia::render('Dashboard', [
            'todo' => Inertia::defer(static function () use ($in, $toArray): array {
                $todo = $in('todo');
                usort($todo, static fn (HomeItem $a, HomeItem $b): int => $a->weight <=> $b->weight);

                return array_map($toArray, $todo);
            }, 'cruscotto'),
            'today' => Inertia::defer(static function () use ($in, $toArray): array {
                $today = $in('today');
                usort($today, static fn (HomeItem $a, HomeItem $b): int => strcmp((string) $a->time, (string) $b->time));

                return array_map($toArray, array_slice($today, 0, 12));
            }, 'cruscotto'),
            'stats' => Inertia::defer(static fn (): array => array_map($toArray, $in('stat')), 'cruscotto'),
            'greeting' => $this->greeting(),
            'setupPending' => $setupPending,
            'canSetup' => $operator->can(Ability::ManageSettings),
            // Query senza filtro: la Row-Level Security mostra solo le schede di questa associazione.
            'peopleCount' => Inertia::defer(fn (): int => $this->db->connection($this->context->require()->connection)
                ->table('app.people')->whereNull('deleted_at')->count(), 'cruscotto'),
        ]);
    }

    private function greeting(): string
    {
        $h = (int) now('Europe/Rome')->format('G');

        return $h >= 6 && $h < 13 ? 'Buongiorno' : ($h >= 13 && $h < 18 ? 'Buon pomeriggio' : 'Buonasera');
    }
}
