<?php

declare(strict_types=1);

namespace App\Modules\Portale\Http\Controllers;

use App\Modules\Portale\Admin\AdminApi;
use App\Modules\Portale\Admin\ReplayedToken;
use App\Modules\Portale\Admin\UnknownUser;
use App\Modules\Portale\Admin\WrongScope;
use App\Modules\Portale\Entitlements\UnknownOrg;
use App\Modules\Portale\Jwt\InvalidToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Amministrazione della piattaforma per il portale (CONTRATTO_PORTALE.md §2.3). Corpo: {"token": "<JWT>"}; niente
 * sessione né cookie. Tutte POST: il token non sta mai nell'indirizzo.
 */
final readonly class AdminApiController
{
    public function __construct(private AdminApi $api) {}

    public function orgs(Request $request): JsonResponse
    {
        return $this->guard($request, fn (string $token): array => $this->api->orgs($token));
    }

    public function org(Request $request, string $org): JsonResponse
    {
        return $this->guard($request, fn (string $token): array => $this->api->org($token, $org));
    }

    public function operators(Request $request, string $org): JsonResponse
    {
        return $this->guard($request, fn (string $token): array => $this->api->operators($token, $org));
    }

    public function userState(Request $request, string $user): JsonResponse
    {
        return $this->guard($request, fn (string $token): array => $this->api->setUserState($token, $user));
    }

    /** @param  Closure(string): array<string, mixed>  $action */
    private function guard(Request $request, Closure $action): JsonResponse
    {
        $token = $request->json('token');
        if (! is_string($token) || $token === '') {
            return self::error(400, 'missing_token');
        }

        try {
            return new JsonResponse($action($token));
        } catch (InvalidToken) {
            return self::error(401, 'invalid_token');
        } catch (WrongScope) {
            return self::error(403, 'scope');
        } catch (ReplayedToken) {
            return self::error(409, 'replayed');
        } catch (UnknownOrg) {
            return self::error(404, 'unknown_org');
        } catch (UnknownUser) {
            return self::error(404, 'unknown_user');
        } catch (InvalidArgumentException) {
            return self::error(422, 'invalid_claims');
        }
    }

    private static function error(int $status, string $code): JsonResponse
    {
        return new JsonResponse(['error' => $code], $status);
    }
}
