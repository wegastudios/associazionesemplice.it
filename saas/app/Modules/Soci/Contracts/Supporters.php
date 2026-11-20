<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

/** La scheda di chi dona dalla pagina pubblica: la sua, se l'email è già in anagrafica; altrimenti nasce un «Sostenitore» (non socio). */
interface Supporters
{
    /**
     * @return string l'id della scheda
     *
     * @throws PeopleError se i dati non sono validi
     */
    public function personFor(string $firstName, string $lastName, string $email, ?string $taxCode): string;
}
