<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\SetupStep;
use App\Modules\Core\Org\SetupWizard;
use App\Modules\Soci\Contracts\MembershipRealignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Configurazione guidata: una pagina con i passi dei moduli, salvati insieme. */
final readonly class SetupController
{
    public function __construct(
        private SetupWizard $wizard,
        private CurrentOperator $current,
        private MembershipRealignment $cards,
    ) {}

    /** Anteprima di cosa succede alle tessere se l'anno fiscale cambia mese (prima di salvare). */
    public function cardsPreview(Request $request): JsonResponse
    {
        $month = $request->integer('mese');

        return response()->json($month >= 1 && $month <= 12 ? $this->cards->preview($month) : ['moved' => 0, 'superseded' => 0, 'lines' => [], 'more' => 0]);
    }

    public function show(): Response
    {
        $operator = $this->operator();

        return Inertia::render('Configurazione', [
            'status' => $this->wizard->status(),
            'canSkip' => ! $this->wizard->mustChoose($operator),
            'steps' => array_map(static fn (SetupStep $s): array => ['key' => $s->key(), 'title' => $s->title(), 'props' => $s->props()], $this->wizard->steps($operator)),
            'can' => [
                'operators' => $operator->can(Ability::ManageOperators),
                'people' => $operator->can(Ability::EditPeople),
            ],
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $input = $request->all();
        $errors = $this->wizard->save($this->operator(), is_array($input) ? $input : []);
        if ($errors !== []) {
            $messages = [];
            foreach ($errors as $key => $list) {
                $messages["steps.{$key}"] = implode(' ', $list);
            }
            throw ValidationException::withMessages($messages);
        }

        $notice = session()->pull('setup_notice');

        return redirect()->route('dashboard')->with('status', (is_string($notice) ? $notice.' ' : '').'Configurazione salvata. Puoi riaprirla quando vuoi da Altro › Configurazione guidata.');
    }

    public function skip(): RedirectResponse
    {
        if ($this->wizard->mustChoose($this->operator())) {
            return redirect()->route('setup')->withErrors(['steps.anno_sociale' => 'Prima scegli il mese da cui iniziano corsi ed eventi (anno delle attività) e quello dell\'anno fiscale: non si rimanda.']);
        }
        $this->wizard->skip();

        return redirect()->route('dashboard')->with('status', 'Va bene, più tardi: la trovi in Altro › Configurazione guidata.');
    }

    private function operator(): Operator
    {
        return $this->current->get() ?? abort(403);
    }
}
