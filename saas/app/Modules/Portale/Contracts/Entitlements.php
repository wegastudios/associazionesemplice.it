<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

interface Entitlements
{
    public function for(string $orgId): OrgEntitlements;
}
