<?php

declare(strict_types=1);

namespace App\Modules\Soci\Home;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\HomeItem;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\ApplicationService;
use App\Modules\Soci\Services\CertificateService;
use App\Modules\Soci\Services\MembershipService;

/** Home: domande di adesione da esaminare, certificati medici e tessere da rinnovare. */
final readonly class SociHome implements HomeWidget
{
    public function __construct(
        private ApplicationService $applications,
        private CertificateService $certificates,
        private MembershipService $memberships,
    ) {}

    public function items(Operator $operator): array
    {
        if (! $operator->can(Ability::ViewPeople)) {
            return [];
        }
        $out = [];
        $pending = $this->applications->enabled() ? $this->applications->pendingCount() : 0;
        if ($pending > 0) {
            $out[] = HomeItem::todo($pending === 1 ? '1 domanda di adesione da esaminare' : $pending.' domande di adesione da esaminare', '/gestionale/domande', 'warn', 'Approva o rifiuta', 10);
        }

        // I certificati sono dati sanitari: li conta solo chi può vederli.
        ['expiring' => $expiring, 'expired' => $expired] = $operator->can(Ability::ViewHealth) ? $this->certificates->counts() : ['expiring' => 0, 'expired' => 0];
        if ($expired > 0) {
            $out[] = HomeItem::todo($expired === 1 ? '1 certificato medico scaduto' : $expired.' certificati medici scaduti', '/gestionale/certificati?stato=expired', 'danger', null, 20);
        }
        if ($expiring > 0) {
            $out[] = HomeItem::todo($expiring === 1 ? '1 certificato medico in scadenza' : $expiring.' certificati medici in scadenza', '/gestionale/certificati?stato=expiring', 'warn', null, 30);
        }

        // Un conteggio solo, con le tessere valide in sottoquery: gli id non passano da PHP.
        $withoutCard = Person::query()->whereIn('type', ['ordinary', 'volunteer'])->whereNull('left_on')->whereNull('anonymized_at')
            ->whereNotIn('id', $this->memberships->activeSubquery())->count();
        if ($withoutCard > 0) {
            $out[] = HomeItem::todo($withoutCard === 1 ? '1 socio senza tessera valida' : $withoutCard.' soci senza tessera valida', '/gestionale/soci', 'info', 'Anno fiscale '.$this->memberships->membershipYearLabel(), 40);
        }

        return $out;
    }
}
