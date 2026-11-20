<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

use RuntimeException;

/** Manca la chiave dati di un'associazione: non si crea in silenzio, si segnala. */
final class OrgKeyMissing extends RuntimeException {}
