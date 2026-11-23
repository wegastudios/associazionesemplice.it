<?php

declare(strict_types=1);

namespace App\Modules\Portale\Http\Controllers;

use App\Modules\Portale\Contracts\WebRuntimeReset;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\Jwt\InvalidToken;
use App\Modules\Portale\PortalConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * POST /api/portale/v2/runtime/reset: dopo un rilascio azzera OPcache e la cache dei percorsi del PHP web. Nessun
 * parametro oltre al token, firmato dal SaaS stesso con tipo `runtime_reset` e destinatario proprio (il token del rapporto
 * di sola lettura non vale qui, e questo non vale là). Risponde con due interruttori, nessun dato.
 */
final readonly class RuntimeResetController
{
    public function __construct(private PortalConfig $config) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->json('token');
        if (! is_string($token) || $token === '') {
            return new JsonResponse(['error' => 'missing_token'], 400);
        }

        try {
            $claims = EdDsaJwt::verify($token, $this->config->runtimeKeys(), $this->config->saasUrl(), WebRuntimeReset::AUDIENCE, WebRuntimeReset::TYPE, time());
        } catch (InvalidToken) {
            return new JsonResponse(['error' => 'invalid_token'], 401);
        }

        // Un token vale una volta sola: l'identificativo resta in cache fino alla scadenza (più il margine di tolleranza).
        // `add` è atomico: di due richieste con lo stesso token passa solo la prima.
        $jti = $claims['jti'] ?? null;
        if (! is_string($jti) || $jti === '' || strlen($jti) > 128 || ! Cache::add('runtime-reset-jti:'.hash('sha256', $jti), 1, 180)) {
            return new JsonResponse(['error' => 'invalid_token'], 401);
        }

        return new JsonResponse(WebRuntimeReset::perform());
    }
}
