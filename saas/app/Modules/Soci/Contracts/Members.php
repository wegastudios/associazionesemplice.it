<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

/** Soci attivi dell'associazione corrente: con tessera valida a quella data, o fondatori. */
interface Members
{
    public function activeCount(?string $date = null): int;

    public function isActive(string $personId, ?string $date = null): bool;

    /** Etichetta dell'anno sociale di quella data («2026» o «2025/2026»). */
    public function socialYearLabel(?string $date = null): string;

    /**
     * L'anno sociale di un'etichetta: primo e ultimo giorno, i dodici mesi; null se l'etichetta non è valida.
     *
     * @return array{label: string, start: string, end: string, months: list<string>, next: string, previous: string}|null
     */
    public function socialYearInfo(string $label): ?array;
}
