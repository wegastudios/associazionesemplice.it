<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Errore di dominio con codice stabile e stato HTTP, reso in formato uniforme.
 */
class SupportException extends RuntimeException
{
    /** @param  array<string, mixed>  $details  informazioni aggiuntive per il chiamante (es. priorita consentite) */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param  array<string, mixed>  $details */
    public static function of(string $code, string $message, int $status = 422, array $details = []): self
    {
        return new self($code, $message, $status, $details);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'error' => array_merge([
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'correlation_id' => app()->bound('correlation_id') ? app('correlation_id') : null,
            ], $this->details),
        ], $this->status);
    }
}
