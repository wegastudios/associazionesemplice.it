<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Access\OrgTwoFactor;
use App\Modules\Core\Auth\OrgTwoFactorRequiredNotification;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\Input;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OperatorLimitReached;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
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
        private OrgTwoFactor $twoFactor,
        private AuditLog $audit,
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
                'me' => $o['user_id'] === $by->userId, 'denied' => $o['denied'],
                'editable' => $o['user_id'] !== $by->userId && $by->role->canAssign($o['role']),
            ], $this->members->operators($org)),
            'roles' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'name' => $r['name'], 'members' => $r['members'],
                'abilities' => array_map(static fn (Ability $a): string => $a->value, $r['abilities']),
            ], $roles),
            'fixedRoles' => array_values(array_map(
                static fn (OrgRole $r): array => ['value' => $r->value, 'label' => $r->label(), 'abilities' => array_values(array_map(static fn (Ability $a): string => $a->label(), array_filter(Ability::cases(), static fn (Ability $a): bool => $a->parent() === null && $a->allows($r))))],
                array_filter(OrgRole::cases(), static fn (OrgRole $r): bool => $r !== OrgRole::Custom && $by->role->canAssign($r)),
            )),
            'removable' => array_map(static fn (Ability $a): array => ['value' => $a->value, 'label' => $a->label()], Ability::removable()),
            'abilities' => array_map(static fn (Ability $a): array => [
                'value' => $a->value, 'label' => $a->label(), 'requires' => array_map(static fn (Ability $r): string => $r->value, $a->requires()),
            ], Ability::assignable()),
            'twoFactorRequired' => $this->twoFactor->requiredHere(),
            'twoFactorMembersRequired' => $this->twoFactor->membersRequiredHere(),
        ]);
    }

    /**
     * Verifica in due passaggi obbligatoria per chi lavora nell'associazione. Per accenderla bisogna averla attiva: chi la
     * chiede non si chiude fuori da solo. Accesa, chi non l'ha ancora riceve un'email e la attiva al primo accesso.
     */
    public function twoFactor(Request $request): RedirectResponse
    {
        $required = Input::of($request->validate(['required' => ['required', 'boolean']]))['required'];
        $required = $required === true || $required === 1 || $required === '1' || $required === 'true';
        $by = $this->by();
        $user = $request->user();

        if ($required && (! $user instanceof User || ! $user->hasConfirmedTwoFactor())) {
            throw ValidationException::withMessages(['ledger' => 'Prima attiva la verifica in due passaggi per te, dal tuo profilo: poi potrai chiederla a tutti.']);
        }
        if ($required === $this->twoFactor->requiredHere()) {
            return back();
        }

        $this->twoFactor->setRequiredHere($required);
        $this->audit->record($required ? 'org.two_factor.required' : 'org.two_factor.optional');

        if (! $required) {
            return back()->with('status', 'Verifica in due passaggi non più obbligatoria: ognuno sceglie per sé.');
        }

        $without = array_filter($this->members->operators($this->org($by)), static fn (array $o): bool => ! $o['two_factor']);
        foreach (User::query()->whereIn('id', array_map(static fn (array $o): string => $o['user_id'], $without))->get() as $operator) {
            $operator->notify(new OrgTwoFactorRequiredNotification($operator->name, $by->orgName));
        }

        return back()->with('status', $without === []
            ? 'Verifica in due passaggi obbligatoria: tutti l\'hanno già attiva.'
            : 'Verifica in due passaggi obbligatoria. '.(count($without) === 1 ? '1 persona non l\'ha ancora attiva: ha ricevuto un\'email e la attiverà al prossimo accesso.' : count($without).' persone non l\'hanno ancora attiva: hanno ricevuto un\'email e la attiveranno al prossimo accesso.'));
    }

    /** Verifica in due passaggi chiesta ai soci nell'area soci: chi non l'ha la accende al primo ingresso, prima del resto. */
    public function twoFactorMembers(Request $request): RedirectResponse
    {
        $required = Input::of($request->validate(['required' => ['required', 'boolean']]))['required'];
        $required = $required === true || $required === 1 || $required === '1' || $required === 'true';
        $this->by();
        if ($required === $this->twoFactor->membersRequiredHere()) {
            return back();
        }
        $this->twoFactor->setMembersRequiredHere($required);
        $this->audit->record($required ? 'org.two_factor.members_required' : 'org.two_factor.members_optional');

        return back()->with('status', $required
            ? 'Verifica in due passaggi chiesta ai soci: chi non l\'ha la accenderà al prossimo ingresso nell\'area soci.'
            : 'Verifica in due passaggi non più chiesta ai soci: ognuno sceglie per sé.');
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

    public function permissions(Request $request, string $user): RedirectResponse
    {
        $data = Input::of($request->validate([
            'denied' => ['array'],
            'denied.*' => ['string', Rule::in(array_map(static fn (Ability $a): string => $a->value, Ability::removable()))],
        ]));
        $denied = array_values(array_filter(is_array($data['denied'] ?? null) ? $data['denied'] : [], is_string(...)));

        return $this->attempt(function () use ($denied, $user): string {
            $by = $this->by();
            $this->members->setDeniedAbilities($this->org($by), $user, $denied, $by);

            return 'Permessi del dipendente aggiornati.';
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
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }
    }
}
