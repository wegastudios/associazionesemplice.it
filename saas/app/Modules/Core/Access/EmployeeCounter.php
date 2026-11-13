<?php

declare(strict_types=1);

namespace App\Modules\Core\Access;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\StaffCount;
use Illuminate\Database\DatabaseManager;

final readonly class EmployeeCounter implements StaffCount
{
    public function __construct(private DatabaseManager $db, private OrgContext $context) {}

    public function employees(): int
    {
        return $this->db->connection('pgsql')->table('core.org_users')
            ->where('org_id', $this->context->require()->id)->where('role', OrgRole::Employee->value)->count();
    }
}
