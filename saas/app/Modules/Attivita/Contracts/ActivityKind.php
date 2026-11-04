<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Contracts;

/**
 * Tipi di attività (dall'ActivityKind del plugin). Il contributo può essere nullo (attività gratuita) e per gli ospiti
 * può essere diverso da quello dei soci. Gli eventi ricorrenti, con le prenotazioni alle singole date, arrivano con
 * il calendario.
 *
 *  - course  Corso: iscrizione per mesi, contributo mensile, trimestrale o una tantum.
 *  - event   Evento: un contributo per partecipante.
 */
enum ActivityKind: string
{
    case Course = 'course';
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'Corso',
            self::Event => 'Evento',
        };
    }
}
