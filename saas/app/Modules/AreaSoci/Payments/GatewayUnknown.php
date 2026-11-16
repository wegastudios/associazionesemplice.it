<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Payments;

use RuntimeException;

/** Il gestore ha risposto con un errore suo (5xx): l'operazione può essere partita lo stesso, l'esito è da verificare. */
final class GatewayUnknown extends RuntimeException {}
