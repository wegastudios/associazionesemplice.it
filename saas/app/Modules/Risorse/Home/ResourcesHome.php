<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Home;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\HomeItem;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;
use App\Modules\Risorse\Models\Resource;
use App\Modules\Risorse\Services\ReservationService;

/** Home: prenotazioni e blocchi di oggi (solo con il pacchetto Risorse). */
final readonly class ResourcesHome implements HomeWidget
{
    public function __construct(
        private ReservationService $reservations,
        private Entitlements $entitlements,
    ) {}

    public function items(Operator $operator): array
    {
        if (! $operator->can(Ability::ViewPeople) || ! $this->entitlements->for($operator->orgId)->has(Package::Resources)) {
            return [];
        }
        $today = substr(ReservationService::now(), 0, 10);
        $names = Resource::query()->pluck('name', 'id')->all();
        $out = [];
        foreach ($this->reservations->between($today.' 00:00:00', date('Y-m-d', (int) strtotime($today.' +1 day')).' 00:00:00') as $b) {
            // Solo ciò che comincia oggi: un posto barca assegnato per la stagione non è un fatto di oggi.
            if (substr($b['starts_at'], 0, 10) !== $today) {
                continue;
            }
            $res = is_string($names[$b['resource_id']] ?? null) ? $names[$b['resource_id']] : 'Risorsa';
            $time = substr($b['starts_at'], 11, 5);
            $out[] = HomeItem::today($time, $res, $b['kind'] === 'block' ? 'Bloccata'.($b['note'] !== null ? ': '.$b['note'] : '') : (string) $b['who'], '/gestionale/risorse/'.$b['resource_id']);
        }

        return $out;
    }
}
