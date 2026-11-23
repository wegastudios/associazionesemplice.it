<?php

declare(strict_types=1);

namespace App\Modules\Core\Export;

use RuntimeException;

/** Copia di sicurezza che non si può chiedere (un'altra è in preparazione) o non si può più scaricare (scaduta). */
final class ExportUnavailable extends RuntimeException {}
