<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** L'operatore collegato e il suo ruolo nell'associazione corrente (fisso o personalizzato). */
final readonly class Operator
{
    /**
     * @param  list<Ability>  $customAbilities  i permessi del ruolo personalizzato
     * @param  list<Ability>  $deniedAbilities  i permessi tolti a un dipendente
     */
    public function __construct(
        public string $userId,
        public string $orgId,
        public string $orgName,
        public OrgRole $role,
        public ?string $customRoleId = null,
        public ?string $customRoleName = null,
        public array $customAbilities = [],
        public array $deniedAbilities = [],
    ) {}

    public function can(Ability $ability): bool
    {
        if ($this->role === OrgRole::Custom) {
            return in_array($ability->parent() ?? $ability, $this->customAbilities, true);
        }

        return $ability->allows($this->role) && ! ($this->role === OrgRole::Employee && in_array($ability, $this->deniedAbilities, true));
    }

    public function roleLabel(): string
    {
        return $this->role === OrgRole::Custom && $this->customRoleName !== null ? $this->customRoleName : $this->role->label();
    }
}
