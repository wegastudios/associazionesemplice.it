<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** L'operatore collegato e il suo ruolo nell'associazione corrente (fisso o personalizzato). */
final readonly class Operator
{
    /** @param  list<Ability>  $customAbilities  i permessi del ruolo personalizzato */
    public function __construct(
        public string $userId,
        public string $orgId,
        public string $orgName,
        public OrgRole $role,
        public ?string $customRoleId = null,
        public ?string $customRoleName = null,
        public array $customAbilities = [],
    ) {}

    public function can(Ability $ability): bool
    {
        return $this->role === OrgRole::Custom ? in_array($ability, $this->customAbilities, true) : $ability->allows($this->role);
    }

    public function roleLabel(): string
    {
        return $this->role === OrgRole::Custom && $this->customRoleName !== null ? $this->customRoleName : $this->role->label();
    }
}
