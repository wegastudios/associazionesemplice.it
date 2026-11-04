<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** L'operatore collegato e il suo ruolo nell'associazione corrente. */
final readonly class Operator
{
    public function __construct(
        public string $userId,
        public string $orgId,
        public string $orgName,
        public OrgRole $role,
    ) {}

    public function can(Ability $ability): bool
    {
        return $ability->allows($this->role);
    }
}
