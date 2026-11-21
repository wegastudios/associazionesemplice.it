<?php

declare(strict_types=1);

namespace App\Modules\Portale\Http\Controllers;

use App\Modules\Portale\Contracts\WebRuntimeProbe;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\Jwt\InvalidToken;
use App\Modules\Portale\PortalConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/portale/v2/runtime: come gira il PHP che serve le pagine (SAPI, APCu, OPcache), per i controlli di salute
 * e per le misure. Token firmato dal SaaS stesso (`typ` runtime_probe, destinatario proprio): un token di un'altra
 * rotta non vale. Risponde con soli numeri e interruttori, nessun dato dell'applicazione.
 */
final readonly class RuntimeController
{
    public function __construct(private PortalConfig $config) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->json('token');
        if (! is_string($token) || $token === '') {
            return new JsonResponse(['error' => 'missing_token'], 400);
        }

        try {
            EdDsaJwt::verify($token, $this->config->runtimeKeys(), $this->config->saasUrl(), WebRuntimeProbe::AUDIENCE, WebRuntimeProbe::TYPE, time());
        } catch (InvalidToken) {
            return new JsonResponse(['error' => 'invalid_token'], 401);
        }

        return new JsonResponse(WebRuntimeProbe::report());
    }
}
