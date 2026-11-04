<?php

declare(strict_types=1);

namespace App\Modules\Core\Access;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Auth\InvitationNotification;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OperatorLimitReached;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
use App\Modules\Portale\Contracts\Entitlements;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Operatori di un'associazione: invito, cambio di ruolo, rimozione.
 *
 * Chi invita deve poter assegnare il ruolo (OrgRole::canAssign): un amministratore non crea titolari.
 * L'invitato riceve un link per scegliere la password; se ha già un utente, si aggiunge solo il ruolo.
 * Il primo titolare di un'associazione nasce dal portale, senza chi invita ($by = null).
 */
final readonly class OrgMembers
{
    public function __construct(
        private DatabaseManager $db,
        private PasswordBroker $passwords,
        private Memberships $memberships,
        private OrgContext $context,
        private AuditLog $audit,
        private Entitlements $entitlements,
    ) {}

    public function invite(Org $org, string $email, string $name, OrgRole $role, ?Operator $by, ?string $customRoleId = null): User
    {
        $this->authorize($org, $role, $by);
        $customRoleId = $this->customRole($org, $role, $customRoleId);

        $email = Str::lower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email non valida.');
        }
        if ($by !== null) {
            $this->assertOperatorAvailable($org, $email);
        }

        return $this->db->connection('pgsql')->transaction(function () use ($org, $email, $name, $role, $customRoleId): User {
            $user = User::query()->where('email', $email)->first();
            $isNew = $user === null;

            if ($user === null) {
                $user = new User;
                $user->name = trim($name);
                $user->email = $email;
                // Password casuale che nessuno conosce: l'invitato sceglie la sua dal link.
                $user->password = Hash::make(Str::random(64));
                $user->save();
            }

            $now = now();
            $this->db->connection('pgsql')->table('core.org_users')->upsert(
                [['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role->value, 'custom_role_id' => $customRoleId, 'created_at' => $now, 'updated_at' => $now]],
                ['org_id', 'user_id'],
                ['role', 'custom_role_id', 'updated_at'],
            );

            $this->audit($org, 'operator.invited', $user->id, ['role' => $role->value, 'new_user' => $isNew] + ($customRoleId === null ? [] : ['custom_role_id' => $customRoleId]));
            $user->notify(new InvitationNotification($org->name, $isNew ? $this->passwords->createToken($user) : null));

            return $user;
        });
    }

    public function changeRole(Org $org, string $userId, OrgRole $role, Operator $by, ?string $customRoleId = null): void
    {
        $current = $this->memberships->find($userId, $org->id) ?? throw new InvalidArgumentException('Operatore inesistente.');
        $this->authorize($org, $current->role, $by);
        $this->authorize($org, $role, $by);
        $customRoleId = $this->customRole($org, $role, $customRoleId);
        $this->keepAnOwner($org, $current->role, $role);
        if ($userId === $by->userId && $role !== $current->role) {
            throw new InvalidArgumentException('Il tuo ruolo lo cambia un altro titolare o amministratore.');
        }

        $this->db->connection('pgsql')->table('core.org_users')
            ->where('org_id', $org->id)->where('user_id', $userId)
            ->update(['role' => $role->value, 'custom_role_id' => $customRoleId, 'updated_at' => now()]);
        $this->audit($org, 'operator.role_changed', $userId, ['from' => $current->role->value, 'to' => $role->value]
            + ($customRoleId === null ? [] : ['custom_role_id' => $customRoleId]));
    }

    /**
     * Operatori dell'associazione, con il ruolo e lo stato dell'accesso.
     *
     * @return list<array{user_id: string, name: string, email: string, role: OrgRole, custom_role_id: string|null, role_label: string, two_factor: bool}>
     */
    public function operators(Org $org): array
    {
        $rows = $this->db->connection('pgsql')->table('core.org_users as m')
            ->join('core.users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('core.org_roles as r', 'r.id', '=', 'm.custom_role_id')
            ->where('m.org_id', $org->id)
            ->orderByRaw("array_position(ARRAY['owner', 'admin', 'operator', 'custom', 'accountant'], m.role)")->orderBy('u.name')
            ->get(['m.user_id', 'u.name', 'u.email', 'm.role', 'm.custom_role_id', 'r.name as custom_name', 'u.two_factor_confirmed_at']);

        $out = [];
        foreach ($rows as $r) {
            /** @var object{user_id: string, name: string, email: string, role: string, custom_role_id: string|null, custom_name: string|null, two_factor_confirmed_at: string|null} $r */
            $role = OrgRole::from($r->role);
            $out[] = [
                'user_id' => $r->user_id, 'name' => $r->name, 'email' => $r->email, 'role' => $role, 'custom_role_id' => $r->custom_role_id,
                'role_label' => $role === OrgRole::Custom && $r->custom_name !== null ? $r->custom_name : $role->label(),
                'two_factor' => $r->two_factor_confirmed_at !== null,
            ];
        }

        return $out;
    }

    /**
     * Ruoli personalizzati dell'associazione, con quanti operatori li hanno.
     *
     * @return list<array{id: string, name: string, abilities: list<Ability>, members: int}>
     */
    public function roles(Org $org): array
    {
        $rows = $this->db->connection('pgsql')->table('core.org_roles as r')
            ->where('r.org_id', $org->id)->orderByRaw('lower(r.name)')
            ->selectRaw('r.id, r.name, r.abilities::text AS abilities, (SELECT count(*) FROM core.org_users m WHERE m.custom_role_id = r.id) AS members')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            /** @var object{id: string, name: string, abilities: string, members: int|string} $r */
            $out[] = ['id' => $r->id, 'name' => $r->name, 'abilities' => Memberships::abilities($r->abilities), 'members' => (int) $r->members];
        }

        return $out;
    }

    /**
     * Crea o modifica un ruolo personalizzato. Valgono subito per chi lo ha, alla richiesta successiva.
     *
     * @param  list<string>  $abilities
     */
    public function saveRole(Org $org, ?string $roleId, string $name, array $abilities, Operator $by): string
    {
        $this->authorize($org, OrgRole::Custom, $by);
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('Dai al ruolo un nome (al massimo 60 caratteri).');
        }
        if (in_array(mb_strtolower($name), array_map(static fn (OrgRole $r): string => mb_strtolower($r->label()), OrgRole::cases()), true)) {
            throw new InvalidArgumentException('Questo nome è già di un ruolo fisso: scegline un altro.');
        }
        $list = Ability::normalize($abilities);
        if ($list === []) {
            throw new InvalidArgumentException('Scegli almeno un permesso.');
        }
        $array = '{'.implode(',', array_map(static fn (Ability $a): string => $a->value, $list)).'}';
        $table = $this->db->connection('pgsql')->table('core.org_roles');
        $duplicate = (clone $table)->where('org_id', $org->id)->whereRaw('lower(btrim(name)) = lower(?)', [$name]);
        if ($roleId !== null) {
            $duplicate->where('id', '!=', $roleId);
        }
        if ($duplicate->exists()) {
            throw new InvalidArgumentException('Esiste già un ruolo con questo nome.');
        }

        if ($roleId === null) {
            $roleId = Str::uuid7()->toString();
            $table->insert(['id' => $roleId, 'org_id' => $org->id, 'name' => $name, 'abilities' => $array]);
            $action = 'role.created';
        } else {
            if (! Str::isUuid($roleId) || $table->where('org_id', $org->id)->where('id', $roleId)->update(['name' => $name, 'abilities' => $array, 'updated_at' => now()]) === 0) {
                throw new InvalidArgumentException('Ruolo inesistente.');
            }
            $action = 'role.updated';
        }
        $this->audit($org, $action, $by->userId, ['role_id' => $roleId, 'name' => $name, 'abilities' => implode(',', array_map(static fn (Ability $a): string => $a->value, $list))]);

        return $roleId;
    }

    public function deleteRole(Org $org, string $roleId, Operator $by): void
    {
        $this->authorize($org, OrgRole::Custom, $by);
        if (! Str::isUuid($roleId)) {
            throw new InvalidArgumentException('Ruolo inesistente.');
        }
        if ($this->db->connection('pgsql')->table('core.org_users')->where('org_id', $org->id)->where('custom_role_id', $roleId)->exists()) {
            throw new InvalidArgumentException('Qualcuno ha ancora questo ruolo: dagli prima un altro ruolo.');
        }
        if ($this->db->connection('pgsql')->table('core.org_roles')->where('org_id', $org->id)->where('id', $roleId)->delete() === 0) {
            throw new InvalidArgumentException('Ruolo inesistente.');
        }
        $this->audit($org, 'role.deleted', $by->userId, ['role_id' => $roleId]);
    }

    /** Il ruolo personalizzato scelto: obbligatorio con OrgRole::Custom, dell'associazione; null per i ruoli fissi. */
    private function customRole(Org $org, OrgRole $role, ?string $customRoleId): ?string
    {
        if ($role !== OrgRole::Custom) {
            return null;
        }
        if ($customRoleId === null || ! Str::isUuid($customRoleId)
            || ! $this->db->connection('pgsql')->table('core.org_roles')->where('org_id', $org->id)->where('id', $customRoleId)->exists()) {
            throw new InvalidArgumentException('Scegli uno dei ruoli dell\'associazione.');
        }

        return $customRoleId;
    }

    public function remove(Org $org, string $userId, Operator $by): void
    {
        $current = $this->memberships->find($userId, $org->id) ?? throw new InvalidArgumentException('Operatore inesistente.');
        if ($userId === $by->userId) {
            throw new InvalidArgumentException('Non puoi toglierti da solo: chiedilo a un altro titolare o amministratore.');
        }
        $this->authorize($org, $current->role, $by);
        $this->keepAnOwner($org, $current->role, null);

        $this->db->connection('pgsql')->table('core.org_users')
            ->where('org_id', $org->id)->where('user_id', $userId)
            ->delete();
        $this->audit($org, 'operator.removed', $userId, ['role' => $current->role->value]);
    }

    /** @param  array<string, scalar|null>  $details */
    private function audit(Org $org, string $action, string $userId, array $details): void
    {
        $this->context->run($org->id, fn () => $this->audit->record($action, 'core.users', $userId, $details));
    }

    /**
     * Gli operatori sono un limite dell'abbonamento (operatori aggiuntivi a pagamento). Vale per gli inviti fatti
     * dall'associazione con un abbonamento sul portale; il primo titolare, gli interventi dell'assistenza (senza $by)
     * e le associazioni create a mano (pilota) non sono limitati.
     */
    private function assertOperatorAvailable(Org $org, string $email): void
    {
        if ($this->db->connection('pgsql')->table('core.orgs')->where('id', $org->id)->value('portal_ref') === null) {
            return;
        }

        $table = $this->db->connection('pgsql')->table('core.org_users as m')->where('m.org_id', $org->id);
        $already = (clone $table)->join('core.users as u', 'u.id', '=', 'm.user_id')->where('u.email', $email)->exists();
        if (! $already && $table->count() >= $this->entitlements->for($org->id)->operators) {
            throw new OperatorLimitReached('Hai raggiunto il numero di operatori del tuo abbonamento: aggiungine uno dal portale.');
        }
    }

    private function authorize(Org $org, OrgRole $role, ?Operator $by): void
    {
        if ($by === null) {
            return;
        }
        if ($by->orgId !== $org->id || ! $by->role->canAssign($role)) {
            throw new AuthorizationException('Non puoi assegnare questo ruolo.');
        }
    }

    private function keepAnOwner(Org $org, OrgRole $from, ?OrgRole $to): void
    {
        if ($from !== OrgRole::Owner || $to === OrgRole::Owner) {
            return;
        }
        $owners = $this->db->connection('pgsql')->table('core.org_users')
            ->where('org_id', $org->id)->where('role', OrgRole::Owner->value)->count();
        if ($owners <= 1) {
            throw new InvalidArgumentException("L'associazione deve avere almeno un titolare.");
        }
    }
}
