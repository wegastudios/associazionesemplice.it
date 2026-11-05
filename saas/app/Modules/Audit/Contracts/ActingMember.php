<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

/**
 * Il socio che sta agendo dall'area soci (la persona), se c'è: il registro lo annota come actor «member». Lo imposta
 * il middleware dell'area soci per la durata della richiesta.
 */
final class ActingMember
{
    private ?string $personId = null;

    public function set(?string $personId): void
    {
        $this->personId = $personId;
    }

    public function get(): ?string
    {
        return $this->personId;
    }
}
