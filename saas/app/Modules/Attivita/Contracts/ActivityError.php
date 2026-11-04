<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Contracts;

use RuntimeException;

/** Errore da mostrare all'operatore (dati dell'attività o dell'iscrizione non validi). */
final class ActivityError extends RuntimeException {}
