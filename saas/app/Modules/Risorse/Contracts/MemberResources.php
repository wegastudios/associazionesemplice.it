<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Contracts;

/**
 * Le risorse viste dall'area soci: solo quelle a ore o a giornata delle tipologie che il pacchetto ha in versione Web.
 * Il socio vede le fasce libere di un giorno, prenota per sé (o per un figlio), annulla le sue prenotazioni future non
 * pagate. Errori per il socio come ResourceError.
 *
 * @phpstan-type MemberResource array{id: string, name: string, typology: string, typology_label: string, description: string|null, fee: int, nonmember_fee: int|null, max_slots: int, guests_max: int}
 * @phpstan-type MemberSlot array{start: string, end: string, free: bool, past: bool, price: int}
 * @phpstan-type MemberReservation array{id: string, resource: string, starts_at: string, ends_at: string|null, fee: int, paid: int, cancellable: bool}
 */
interface MemberResources
{
    /** @return list<MemberResource> */
    public function available(): array;

    /** @return list<MemberSlot> le fasce di un giorno (vuoto per le risorse a giornata) */
    public function slots(string $resourceId, string $date): array;

    /** @return bool la risorsa a giornata è libera quel giorno */
    public function dayFree(string $resourceId, string $date): bool;

    /** @param  array{date: string, start?: string, units?: int, guests?: list<string>}  $in */
    public function book(string $resourceId, string $personId, array $in): string;

    /**
     * Le prenotazioni future (o in corso) di queste persone.
     *
     * @param  list<string>  $personIds
     * @return list<MemberReservation>
     */
    public function mine(array $personIds): array;

    public function cancel(string $reservationId, string $personId): void;
}
