<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\SetupStep;
use App\Modules\Core\Org\SetupWizard;
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
    ) {}

    public function show(): Response
    {
        $operator = $this->operator();

        return Inertia::render('Configurazione', [
            'status' => $this->wizard->status(),
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

        return redirect()->route('dashboard')->with('status', 'Configurazione salvata. Puoi riaprirla quando vuoi dal riepilogo.');
    }

    public function skip(): RedirectResponse
    {
        $this->wizard->skip();

        return redirect()->route('dashboard')->with('status', 'Configurazione rimandata: la trovi nel riepilogo.');
    }

    private function operator(): Operator
    {
        return $this->current->get() ?? abort(403);
    }
}
