<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Contracts;

/** Quando le attività occupano una risorsa, per chi la prenota o la collega. */
interface ActivityOccupancy
{
    /**
     * Le date di attività (non annullate, non archiviate, con orario) che occupano la risorsa nell'intervallo
     * [$from, $to) (testo «Y-m-d H:i:s»). Una data senza orari occupa il giorno intero; un corso occupa il giorno intero
     * nei giorni delle sue lezioni. Con «occupa solo se prenotata» contano solo le date con prenotazioni valide.
     *
     * Gli eventi collegati in un blocco di manifestazione occupano ognuno il periodo intero del blocco (dal primo inizio
     * all'ultima fine delle loro date non annullate); $exceptBlock salta quel blocco.
     *
     * @return list<array{id: string, activity_id: string, from: string, to: string, label: string}>
     */
    public function busy(string $resourceId, string $from, string $to, ?string $exceptSessionId = null, ?string $exceptActivityId = null, ?string $exceptBlock = null): array;

    /**
     * Il periodo di un blocco sulla risorsa: dal primo inizio all'ultima fine delle date non annullate degli eventi con
     * quell'identificativo, più le finestre $extra («Y-m-d H:i:s» inizio e fine) non ancora salvate, senza la data
     * $exceptSessionId (la sua posizione vecchia non conta). Null se non c'è niente.
     *
     * @param  list<array{0: string, 1: string}>  $extra
     * @return array{from: string, to: string}|null
     */
    public function blockSpan(string $resourceId, string $key, array $extra = [], ?string $exceptSessionId = null): ?array;
}
