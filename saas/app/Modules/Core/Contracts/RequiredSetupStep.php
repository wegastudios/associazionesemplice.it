<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Passo che va completato per primo e non si rimanda (anno sociale e anno fiscale): finché non è stato scelto
 * esplicitamente, la configurazione guidata si riapre da sola e «Più tardi» non c'è.
 */
interface RequiredSetupStep extends SetupStep
{
    /** La scelta è stata fatta (non vale il valore di partenza)? */
    public function satisfied(): bool;
}
