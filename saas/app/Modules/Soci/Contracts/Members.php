<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

/** Soci attivi dell'associazione corrente: con tessera valida a quella data, o fondatori. */
interface Members
{
    public function activeCount(?string $date = null): int;

    public function isActive(string $personId, ?string $date = null): bool;

    /** Etichetta dell'anno delle attività (corsi ed eventi) di quella data («2026» o «2025/2026»). */
    public function socialYearLabel(?string $date = null): string;

    /**
     * L'anno delle attività di un'etichetta: primo e ultimo giorno, i dodici mesi; null se l'etichetta non è valida.
     *
     * @return array{label: string, start: string, end: string, months: list<string>, next: string, previous: string}|null
     */
    public function socialYearInfo(string $label): ?array;

    /** Etichetta dell'anno della tessera di quella data: segue l'anno fiscale. */
    public function membershipYearLabel(?string $date = null): string;

    /**
     * L'anno fiscale della tessera di un'etichetta; null se l'etichetta non è valida.
     *
     * @return array{label: string, start: string, end: string, months: list<string>, next: string, previous: string}|null
     */
    public function membershipYearInfo(string $label): ?array;
}
