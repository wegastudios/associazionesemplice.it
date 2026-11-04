<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Http\Middleware\IdentifyOperatorOrg;
use App\Modules\Core\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Scelta dell'associazione per chi ne segue più d'una (commercialisti, consulenti). */
final readonly class OrgSwitchController
{
    public function __construct(private Memberships $memberships) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Orgs/Choose', [
            'orgs' => array_map(static fn (Operator $o): array => [
                'id' => $o->orgId,
                'name' => $o->orgName,
                'role' => $o->role->label(),
            ], $this->memberships->forUser($user->id)),
        ]);
    }

    public function store(Request $request, string $org): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Si sceglie solo tra le proprie associazioni: per tutte le altre la risposta è la stessa, 404.
        $operator = $this->memberships->find($user->id, $org) ?? abort(404);

        $request->session()->put(IdentifyOperatorOrg::SESSION_KEY, $operator->orgId);
        // Nuovo identificativo di sessione a ogni cambio di associazione.
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
