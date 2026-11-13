<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

/**
 * Cosa può fare un'associazione in base alla fase dell'abbonamento: durante la prova le esportazioni e il
 * collegamento del commercialista dipendono dalle condizioni del catalogo pubblicato (terms.trial). Fuori dalla
 * prova, e per le associazioni senza abbonamento sul portale (pilota), non c'è limite.
 */
interface LifecyclePolicy
{
    /** Messaggio al cliente quando una funzione è riservata a chi sceglie un piano. */
    public const CHOOSE_A_PLAN = 'Disponibile scegliendo un piano.';

    public function exportsAllowed(string $orgId): bool;

    public function accountantLinkAllowed(string $orgId): bool;

    /**
     * Prova finita senza un piano, nei giorni prima della cancellazione: si entra e si consulta, non si inserisce
     * né si modifica, non si esporta; si può acquistare un piano dal portale.
     */
    public function readOnly(string $orgId): bool;
}
