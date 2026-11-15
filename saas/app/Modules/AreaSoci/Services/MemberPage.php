<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Services;

use App\Modules\Core\Contracts\OrgContacts;
use App\Modules\Risorse\Contracts\MemberResources;

/** I dati comuni a tutte le pagine del socio: associazione, schede, sezioni attive. */
final readonly class MemberPage
{
    public function __construct(
        private CurrentMember $current,
        private MemberResources $resources,
        private OrgContacts $contacts,
    ) {}

    /** @return array{org: string, active: string, profiles: list<array{id: string, name: string, own: bool}>, resources: bool, contacts: array{email: string, phone: string}|null} */
    public function props(): array
    {
        $m = $this->current->require();

        return ['org' => $m['org_name'], 'active' => $m['active'], 'profiles' => $m['profiles'], 'resources' => $this->resources->available() !== [], 'contacts' => $this->contacts->all()];
    }
}
