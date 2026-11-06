<?php

declare(strict_types=1);

namespace App\Modules\Soci\Home;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\HomeItem;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\ExpiryRules;
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
            $out[] = HomeItem::todo($pending === 1 ? '1 domanda di adesione da esaminare' : $pending.' domande di adesione da esaminare', '/gestionale/domande', 'warn', 'Accogli o respingi', 10);
        }

        $expiring = 0;
        $expired = 0;
        // I certificati sono dati sanitari: li conta solo chi può vederli.
        foreach ($operator->can(Ability::ViewHealth) ? $this->certificates->overview() : [] as $c) {
            $expiring += $c['status'] === ExpiryRules::EXPIRING ? 1 : 0;
            $expired += $c['status'] === ExpiryRules::EXPIRED ? 1 : 0;
        }
        if ($expired > 0) {
            $out[] = HomeItem::todo($expired === 1 ? '1 certificato medico scaduto' : $expired.' certificati medici scaduti', '/gestionale/certificati?stato=expired', 'danger', null, 20);
        }
        if ($expiring > 0) {
            $out[] = HomeItem::todo($expiring === 1 ? '1 certificato medico in scadenza' : $expiring.' certificati medici in scadenza', '/gestionale/certificati?stato=expiring', 'warn', null, 30);
        }

        $ids = Person::query()->whereIn('type', ['ordinary', 'volunteer'])->whereNull('left_on')->whereNull('anonymized_at')->pluck('id')->all();
        $withoutCard = count($ids) - count($this->memberships->activeAmong(array_values(array_filter($ids, is_string(...)))));
        if ($withoutCard > 0) {
            $out[] = HomeItem::todo($withoutCard === 1 ? '1 socio senza tessera valida' : $withoutCard.' soci senza tessera valida', '/gestionale/soci', 'info', 'Anno sociale '.$this->memberships->socialYearLabel(), 40);
        }

        return $out;
    }
}
