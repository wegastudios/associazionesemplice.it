<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

/**
 * Dati di una persona per i documenti degli altri moduli (ricevute): nome, codice fiscale, nascita, email. Si aprono i
 * campi cifrati, quindi va usato solo per produrre il documento.
 *
 * @phpstan-type Card array{id: string, first_name: string, last_name: string, tax_code: string|null, birth_date: string|null, email: string|null}
 */
interface People
{
    /** @return Card|null */
    public function card(string $personId): ?array;

    /** @return list<array{id: string, first_name: string, last_name: string, tax_code: string|null, birth_date: string|null, email: string|null, operative: bool}> i genitori o tutori, l'operativo per primo */
    public function guardians(string $personId): array;
}
