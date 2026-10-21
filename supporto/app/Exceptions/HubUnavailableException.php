<?php

namespace App\Exceptions;

use RuntimeException;

/** L'hub non e raggiungibile o ha risposto con un errore temporaneo: si puo ritentare. */
class HubUnavailableException extends RuntimeException {}
