<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Upgrade\FormatUpgrader;
use Illuminate\Support\Str;

/** Associazioni su cui lavora un comando: tutte quelle non cancellate, o solo quella indicata con --org. */
trait SelectsOrgs
{
    /** @return list<string>|null null = il valore di --org non è valido (errore già scritto) */
    private function selectedOrgs(FormatUpgrader $upgrader): ?array
    {
        $only = $this->option('org');
        if (is_string($only) && $only !== '') {
            if (! Str::isUuid($only)) {
                $this->error('--org vuole l\'identificativo (uuid) dell\'associazione.');

                return null;
            }
            $ids = $upgrader->orgIds($only);
            if ($ids === []) {
                $this->error('Associazione inesistente.');

                return null;
            }

            return $ids;
        }

        return $upgrader->orgIds();
    }
}
