<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Services;

use App\Modules\Risorse\Contracts\MemberResources;

/** I dati comuni a tutte le pagine del socio: associazione, schede, sezioni attive. */
final readonly class MemberPage
{
    public function __construct(
        private CurrentMember $current,
        private MemberResources $resources,
    ) {}

    /** @return array{org: string, active: string, profiles: list<array{id: string, name: string, own: bool}>, resources: bool} */
    public function props(): array
    {
        $m = $this->current->require();

        return ['org' => $m['org_name'], 'active' => $m['active'], 'profiles' => $m['profiles'], 'resources' => $this->resources->available() !== []];
    }
}
