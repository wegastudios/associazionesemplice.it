<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/**
 * Controllo notturno dei saldi dei conti (saas:balances:verify): il saldo tenuto dai trigger deve essere il saldo di
 * partenza più i movimenti non stornati. Qui l'esito dell'ultimo giro, per saas:health; mai nomi né identificativi.
 */
interface BalanceCheck
{
    /** @return array{at: int, orgs: int, mismatches: int, failed: int}|null null se il controllo non è mai girato */
    public function lastResult(): ?array;
}
