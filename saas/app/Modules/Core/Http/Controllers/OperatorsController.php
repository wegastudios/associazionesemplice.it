<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\Input;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OperatorLimitReached;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\Org;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/** Operatori dell'associazione e ruoli personalizzati: solo titolare e amministratore (org.operators). */
final readonly class OperatorsController
{
    public function __construct(
        private OrgMembers $members,
        private CurrentOperator $current,
    ) {}

    public function index(): Response
    {
        $by = $this->by();
        $org = $this->org($by);
        $roles = $this->members->roles($org);

        return Inertia::render('Operatori/Elenco', [
            'operators' => array_map(static fn (array $o): array => [
                'user_id' => $o['user_id'], 'name' => $o['name'], 'email' => $o['email'], 'role' => $o['role']->value,
                'custom_role_id' => $o['custom_role_id'], 'role_label' => $o['role_label'], 'two_factor' => $o['two_factor'],
                'me' => $o['user_id'] === $by->userId,
                'editable' => $o['user_id'] !== $by->userId && $by->role->canAssign($o['role']),
            ], $this->members->operators($org)),
            'roles' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'name' => $r['name'], 'members' => $r['members'],
                'abilities' => array_map(static fn (Ability $a): string => $a->value, $r['abilities']),
            ], $roles),
            'fixedRoles' => array_values(array_map(
                static fn (OrgRole $r): array => ['value' => $r->value, 'label' => $r->label(), 'abilities' => array_values(array_map(static fn (Ability $a): string => $a->label(), array_filter(Ability::cases(), static fn (Ability $a): bool => $a->allows($r))))],
                array_filter(OrgRole::cases(), static fn (OrgRole $r): bool => $r !== OrgRole::Custom && $by->role->canAssign($r)),
            )),
            'abilities' => array_map(static fn (Ability $a): array => [
                'value' => $a->value, 'label' => $a->label(), 'requires' => array_map(static fn (Ability $r): string => $r->value, $a->requires()),
            ], Ability::assignable()),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $this->roleInput($request, [
            'email' => ['required', 'email', 'max:190'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        return $this->attempt(function () use ($data): string {
            $by = $this->by();
            $this->members->invite($this->org($by), Input::string($data, 'email'), Input::string($data, 'name'), OrgRole::from(Input::string($data, 'role')), $by, Input::optionalString($data, 'custom_role_id'));

            return 'Invito mandato a '.Input::string($data, 'email').'.';
        });
    }

    public function update(Request $request, string $user): RedirectResponse
    {
        $data = $this->roleInput($request, []);

        return $this->attempt(function () use ($data, $user): string {
            $by = $this->by();
            $this->members->changeRole($this->org($by), $user, OrgRole::from(Input::string($data, 'role')), $by, Input::optionalString($data, 'custom_role_id'));

            return 'Ruolo cambiato.';
        });
    }

    public function remove(string $user): RedirectResponse
    {
        return $this->attempt(function () use ($user): string {
            $by = $this->by();
            $this->members->remove($this->org($by), $user, $by);

            return 'Operatore tolto dall\'associazione.';
        });
    }

    public function saveRole(Request $request, ?string $role = null): RedirectResponse
    {
        $data = Input::of($request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['array'],
            'abilities.*' => ['string', Rule::in(array_map(static fn (Ability $a): string => $a->value, Ability::assignable()))],
        ]));
        $abilities = array_values(array_filter(is_array($data['abilities'] ?? null) ? $data['abilities'] : [], is_string(...)));

        return $this->attempt(function () use ($data, $abilities, $role): string {
            $by = $this->by();
            $this->members->saveRole($this->org($by), $role, Input::string($data, 'name'), $abilities, $by);

            return $role === null ? 'Ruolo creato.' : 'Ruolo salvato: vale subito per chi lo ha.';
        });
    }

    public function deleteRole(string $role): RedirectResponse
    {
        return $this->attempt(function () use ($role): string {
            $by = $this->by();
            $this->members->deleteRole($this->org($by), $role, $by);

            return 'Ruolo eliminato.';
        });
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<mixed>
     */
    private function roleInput(Request $request, array $rules): array
    {
        return Input::of($request->validate($rules + [
            'role' => ['required', Rule::enum(OrgRole::class)],
            'custom_role_id' => ['nullable', 'uuid', 'required_if:role,custom'],
        ]));
    }

    private function by(): Operator
    {
        return $this->current->get() ?? abort(403);
    }

    private function org(Operator $by): Org
    {
        return Org::query()->findOrFail($by->orgId);
    }

    /** @param  Closure(): string  $action */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return back()->with('status', $action());
        } catch (InvalidArgumentException|OperatorLimitReached $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        } catch (AuthorizationException) {
            throw ValidationException::withMessages(['ledger' => 'Non puoi assegnare questo ruolo.']);
        }
    }
}
