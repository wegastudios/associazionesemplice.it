<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth\Actions;

use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Attivazione della verifica in due passaggi: una volta confermata, il segreto non si sostituisce più con
 * `force`, che lascerebbe l'utente con un segreto mai inquadrato e quindi chiuso fuori.
 */
final class EnableTwoFactorOnce extends EnableTwoFactorAuthentication
{
    /**
     * @param  mixed  $user
     * @param  bool  $force
     */
    public function __invoke($user, $force = false): void
    {
        $confirmed = is_object($user) && isset($user->two_factor_confirmed_at);

        parent::__invoke($user, $force === true && ! $confirmed);
    }
}
