<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Setup;

use App\Modules\AreaSoci\Http\Controllers\StaffLinkController;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\SetupStep;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;
use Illuminate\Database\DatabaseManager;

/**
 * Mostra l'indirizzo dell'area soci da dare ai soci (e quello con il dominio personalizzato, se verificato). Non ha
 * campi da salvare. Compare solo con il pacchetto Area soci.
 */
final readonly class MemberAreaAddressStep implements SetupStep
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private Entitlements $entitlements,
    ) {}

    public function key(): string
    {
        return 'area_soci';
    }

    public function title(): string
    {
        return 'Indirizzo dell\'area soci';
    }

    public function order(): int
    {
        return 50;
    }

    public function visible(Operator $operator): bool
    {
        return $operator->can(Ability::ManageSettings) && $this->entitlements->for($operator->orgId)->has(Package::MemberArea);
    }

    public function props(): array
    {
        $orgId = $this->context->require()->id;
        $slug = $this->db->connection('pgsql')->table('core.orgs')->where('id', $orgId)->value('slug');
        $custom = $this->db->connection('pgsql')->table('core.org_domains')->where('org_id', $orgId)->where('state', 'active')->value('domain');
        $urls = [['label' => 'Indirizzo dell\'area soci', 'url' => StaffLinkController::baseUrl(is_string($slug) ? $slug : '').'/area']];
        if (is_string($custom) && $custom !== '') {
            $urls[] = ['label' => 'Indirizzo con il tuo dominio', 'url' => StaffLinkController::baseUrl(is_string($slug) ? $slug : '', $custom).'/area'];
        }

        return ['urls' => $urls];
    }

    public function errors(array $input): array
    {
        return [];
    }

    public function save(array $input): void {}
}
