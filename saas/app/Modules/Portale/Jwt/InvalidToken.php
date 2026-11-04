<?php

declare(strict_types=1);

namespace App\Modules\Portale\Jwt;

use RuntimeException;

/** Token del portale non valido: firma, chiave, destinatario, tipo o scadenza. Il messaggio non contiene il token. */
final class InvalidToken extends RuntimeException {}
