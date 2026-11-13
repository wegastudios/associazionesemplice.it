<?php

declare(strict_types=1);

namespace App\Modules\Portale\Http\Controllers;

use App\Modules\Piattaforma\Contracts\AddonNotAllowed;
use App\Modules\Piattaforma\Contracts\UnknownAddon;
use App\Modules\Piattaforma\Contracts\UnknownPlan;
use App\Modules\Portale\Entitlements\PortalSync;
use App\Modules\Portale\Entitlements\SlugTaken;
use App\Modules\Portale\Entitlements\StaleEntitlements;
use App\Modules\Portale\Entitlements\UnknownOrg;
use App\Modules\Portale\Jwt\InvalidToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * API chiamata dal portale (CONTRATTO_PORTALE.md §3). Corpo: {"token": "<JWT>"}. Niente sessione né cookie: vale
 * solo la firma del token. Gli errori dicono il motivo in un codice, senza ripetere i dati ricevuti.
 */
final readonly class PortalApiController
{
    public function __construct(private PortalSync $sync) {}

    public function provision(Request $request): JsonResponse
    {
        // Senza servizio chiavi la piattaforma è solo di prova: niente associazioni vere dal portale.
        if (config('saas.crypto.trial') === true) {
            return self::error(503, 'key_service_not_configured');
        }

        return $this->guard($request, function (string $token): JsonResponse {
            $result = $this->sync->provision($token);

            return new JsonResponse($result, $result['created'] ? 201 : 200);
        });
    }

    public function entitlements(Request $request, string $org): JsonResponse
    {
        return $this->guard($request, function (string $token) use ($org): JsonResponse {
            $rev = $this->sync->apply($token, $org);

            return new JsonResponse(['org_id' => $org, 'rev' => $rev]);
        });
    }

    /** Abbonamento v2 (CONTRATTO_PORTALE.md §3.2): al posto dei diritti calcolati dal portale. */
    public function subscription(Request $request, string $org): JsonResponse
    {
        return $this->guard($request, function (string $token) use ($org): JsonResponse {
            $result = $this->sync->applySubscription($token, $org);

            return new JsonResponse(['org_id' => $org] + $result);
        });
    }

    /** @param  Closure(string): JsonResponse  $action */
    private function guard(Request $request, Closure $action): JsonResponse
    {
        $token = $request->json('token');
        if (! is_string($token) || $token === '') {
            return self::error(400, 'missing_token');
        }

        try {
            return $action($token);
        } catch (InvalidToken) {
            return self::error(401, 'invalid_token');
        } catch (StaleEntitlements $e) {
            return new JsonResponse(['error' => 'stale_revision', 'current_rev' => $e->current], 409);
        } catch (SlugTaken) {
            return self::error(409, 'slug_taken');
        } catch (UnknownPlan) {
            return self::error(409, 'unknown_plan');
        } catch (UnknownAddon) {
            return self::error(409, 'unknown_addon');
        } catch (AddonNotAllowed) {
            return self::error(409, 'addon_not_allowed');
        } catch (UnknownOrg) {
            return self::error(404, 'unknown_org');
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'invalid_claims', 'detail' => $e->getMessage()], 422);
        }
    }

    private static function error(int $status, string $code): JsonResponse
    {
        return new JsonResponse(['error' => $code], $status);
    }
}
