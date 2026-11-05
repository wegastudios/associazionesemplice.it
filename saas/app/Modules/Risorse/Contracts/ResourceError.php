<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Contracts;

use RuntimeException;

/** Errore da mostrare all'operatore (risorsa o prenotazione non valide). */
final class ResourceError extends RuntimeException {}
