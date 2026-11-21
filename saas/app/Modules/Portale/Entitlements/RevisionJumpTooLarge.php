<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use RuntimeException;

/** La revisione salta troppo oltre l'ultima accettata: non si ritenta, il portale deve correggere il numero. */
final class RevisionJumpTooLarge extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Salto di revisione troppo grande.');
    }
}
