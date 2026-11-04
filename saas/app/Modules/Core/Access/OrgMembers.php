<?php

declare(strict_types=1);

namespace App\Modules\Core\Access;

use App\Modules\Core\Auth\InvitationNotification;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
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
    ) {}

    public function invite(Org $org, string $email, string $name, OrgRole $role, ?Operator $by): User
    {
        $this->authorize($org, $role, $by);

        $email = Str::lower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email non valida.');
        }

        return $this->db->connection('pgsql')->transaction(function () use ($org, $email, $name, $role): User {
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
                [['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role->value, 'created_at' => $now, 'updated_at' => $now]],
                ['org_id', 'user_id'],
                ['role', 'updated_at'],
            );

            $user->notify(new InvitationNotification($org->name, $isNew ? $this->passwords->createToken($user) : null));

            return $user;
        });
    }

    public function changeRole(Org $org, string $userId, OrgRole $role, Operator $by): void
    {
        $current = $this->memberships->find($userId, $org->id) ?? throw new InvalidArgumentException('Operatore inesistente.');
        $this->authorize($org, $current->role, $by);
        $this->authorize($org, $role, $by);
        $this->keepAnOwner($org, $current->role, $role);

        $this->db->connection('pgsql')->table('core.org_users')
            ->where('org_id', $org->id)->where('user_id', $userId)
            ->update(['role' => $role->value, 'updated_at' => now()]);
    }

    public function remove(Org $org, string $userId, Operator $by): void
    {
        $current = $this->memberships->find($userId, $org->id) ?? throw new InvalidArgumentException('Operatore inesistente.');
        $this->authorize($org, $current->role, $by);
        $this->keepAnOwner($org, $current->role, null);

        $this->db->connection('pgsql')->table('core.org_users')
            ->where('org_id', $org->id)->where('user_id', $userId)
            ->delete();
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
