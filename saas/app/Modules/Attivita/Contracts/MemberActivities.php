<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Contracts;

/**
 * Le attività viste dall'area soci: le iscrizioni con la situazione dei contributi, le prossime date degli eventi con
 * prenotazioni, la prenotazione e l'annullamento da parte del socio (al contributo normale, da pagare in segreteria
 * o online quando ci sarà). Errori per il socio come ActivityError.
 *
 * @phpstan-type MemberEvent array{session_id: string, activity_id: string, activity: string, date: string, start: string|null, end: string|null, location: string|null, capacity: int|null, free: int|null, booking_id: string|null, waiting: bool, fee: int, paid: int, cancellable: bool}
 */
interface MemberActivities
{
    /**
     * @param  list<string>  $personIds
     * @return list<array{person_id: string, activity: string, kind: string, from: string, to: string|null, due: int, paid: int, regular: bool}>
     */
    public function enrollments(array $personIds): array;

    /**
     * Le date dei prossimi 60 giorni, con la prenotazione della persona se c'è.
     *
     * @return list<MemberEvent>
     */
    public function upcoming(string $personId): array;

    public function book(string $sessionId, string $personId): void;

    /**
     * Il socio annulla la sua prenotazione, fino al giorno prima.
     *
     * @return list<string> chi è entrato dalla lista d'attesa
     */
    public function cancel(string $bookingId, string $personId): array;

    public function joinWaitlist(string $sessionId, string $personId): void;
}
