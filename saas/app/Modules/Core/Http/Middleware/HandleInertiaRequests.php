<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Dati condivisi con ogni pagina: solo il minimo. Niente dati dei soci qui: li passa ogni pagina, se servono.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user instanceof User ? ['name' => $user->name, 'email' => $user->email] : null,
            ],
            // Funzione: si valuta quando la pagina viene preparata, dopo che il gestionale ha scelto l'associazione.
            'org' => static function (): ?array {
                $operator = app(CurrentOperator::class)->get();

                return $operator === null ? null : [
                    'id' => $operator->orgId,
                    'name' => $operator->orgName,
                    'role' => $operator->roleLabel(),
                    // Per mostrare solo le sezioni permesse; i controlli veri restano nelle rotte.
                    'can' => array_values(array_map(static fn (Ability $a): string => $a->value, array_filter(Ability::cases(), $operator->can(...)))),
                ];
            },
            'flash' => [
                'status' => $request->session()->get('status'),
            ],
        ];
    }
}
