<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Contracts;

/**
 * Tipi di attività (dall'ActivityKind del plugin). Il contributo può essere nullo (attività gratuita) e per gli ospiti
 * può essere diverso da quello dei soci.
 *
 *  - course     Corso: iscrizione per mesi, contributo mensile, trimestrale o una tantum.
 *  - event      Evento: partecipanti iscritti, un contributo per partecipante.
 *  - recurring  Evento con prenotazioni: una o più date con i loro posti, prenotazione alla singola data e contributo
 *               per data (o listino, anche a pacchetto di ingressi), lista d'attesa.
 */
enum ActivityKind: string
{
    case Course = 'course';
    case Event = 'event';
    case Recurring = 'recurring';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'Corso',
            self::Event => 'Evento',
            self::Recurring => 'Evento con prenotazioni',
        };
    }

    /** Ha delle date a cui ci si prenota. */
    public function usesSessions(): bool
    {
        return $this === self::Recurring;
    }
}
