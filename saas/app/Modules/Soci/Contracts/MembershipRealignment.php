<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

/**
 * Che cosa succede alle tessere se il mese di inizio dell'anno fiscale cambia (la tessera segue l'anno fiscale).
 * Prima si mostra l'anteprima e la persona conferma; solo allora si riallinea. Gli anni fiscali chiusi non si toccano.
 *
 * @phpstan-type Preview array{moved: int, superseded: int, lines: list<string>, more: int}
 */
interface MembershipRealignment
{
    /** @return Preview */
    public function preview(int $month): array;

    /** @return array{moved: int, superseded: int} */
    public function apply(int $month): array;
}
