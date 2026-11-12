<?php

declare(strict_types=1);

namespace App\Modules\Portale\Http\Controllers;

use App\Modules\Piattaforma\Contracts\UnknownAddon;
use App\Modules\Piattaforma\Contracts\UnknownPlan;
use App\Modules\Portale\Admin\ReplayedToken;
use App\Modules\Portale\Entitlements\PortalOrgServices;
use App\Modules\Portale\Entitlements\PortalPromos;
use App\Modules\Portale\Entitlements\UnknownOrg;
use App\Modules\Portale\Jwt\InvalidToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Promozioni, cambio di piano, dominio e stato (CONTRATTO_PORTALE.md §3.3–§3.6). Corpo: {"token": "<JWT>"}; vale solo
 * la firma. Gli errori dicono il motivo in un codice, senza ripetere i dati ricevuti.
 */
final readonly class PortalServicesController
{
    public function __construct(private PortalPromos $promos, private PortalOrgServices $orgs) {}

    public function promoCheck(Request $request): JsonResponse
    {
        return $this->guard($request, fn (string $token): JsonResponse => new JsonResponse($this->promos->check($token)));
    }

    public function promoRedeem(Request $request): JsonResponse
    {
        return $this->guard($request, function (string $token): JsonResponse {
            $result = $this->promos->redeem($token);

            return $result === null ? new JsonResponse(['error' => 'exhausted'], 409) : new JsonResponse($result);
        });
    }

    public function planChangeCheck(Request $request, string $org): JsonResponse
    {
        return $this->guard($request, fn (string $token): JsonResponse => new JsonResponse($this->orgs->planChangeCheck($token, $org)));
    }

    public function domainCheck(Request $request, string $org): JsonResponse
    {
        return $this->guard($request, fn (string $token): JsonResponse => new JsonResponse($this->orgs->domainCheck($token, $org)));
    }

    public function domainSet(Request $request, string $org): JsonResponse
    {
        return $this->guard($request, function (string $token) use ($org): JsonResponse {
            $result = $this->orgs->domainSet($token, $org);

            return new JsonResponse($result, ($result['ok'] ?? true) === false ? 409 : 200);
        });
    }

    public function status(Request $request, string $org): JsonResponse
    {
        return $this->guard($request, fn (string $token): JsonResponse => new JsonResponse($this->orgs->status($token, $org)));
    }

    /** @param  Closure(string): JsonResponse  $action */
    private function guard(Request $request, Closure $action): JsonResponse
    {
        $token = $request->json('token');
        if (! is_string($token) || $token === '') {
            return new JsonResponse(['error' => 'missing_token'], 400);
        }

        try {
            return $action($token);
        } catch (InvalidToken) {
            return new JsonResponse(['error' => 'invalid_token'], 401);
        } catch (ReplayedToken) {
            return new JsonResponse(['error' => 'replayed'], 409);
        } catch (UnknownPlan) {
            return new JsonResponse(['error' => 'unknown_plan'], 409);
        } catch (UnknownAddon) {
            return new JsonResponse(['error' => 'unknown_addon'], 409);
        } catch (UnknownOrg) {
            return new JsonResponse(['error' => 'unknown_org'], 404);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'invalid_claims', 'detail' => $e->getMessage()], 422);
        }
    }
}
