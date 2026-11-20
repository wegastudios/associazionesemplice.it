<?php

declare(strict_types=1);

namespace KeyService;

/** Conta le operazioni del minuto in corso, per il limite e l'allarme sulle aperture. */
interface RateCounter
{
    /** Registra un'operazione e dice quante ce ne sono state in questo minuto, questa compresa. */
    public function hit(string $name, int $now): int;
}
