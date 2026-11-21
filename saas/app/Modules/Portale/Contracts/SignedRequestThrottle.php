<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite di frequenza delle API firmate, applicato dopo il controllo della firma: due secchi per indirizzo e per ambito.
 * Chi firma bene ha il suo (`valid_per_minute`) e non è toccato da chi sbaglia dallo stesso indirizzo; chi non firma
 * bene ha un limite basso (`invalid_per_minute`) e oltre quello riceve 429 prima di qualunque altro lavoro.
 */
final readonly class SignedRequestThrottle
{
    public function __construct(private RateLimiter $limiter) {}

    /**
     * @param  Closure(Request): Response  $next
     * @param  array<string, string>  $keys  kid => chiave pubblica base64url dell'ambito
     */
    public function handle(Request $request, Closure $next, string $scope, array $keys): Response
    {
        $token = $request->json('token');
        $valid = is_string($token) && StaffJwt::hasValidSignature($token, $keys);

        $key = ($valid ? 'signed-ok|' : 'signed-bad|').$scope.'|'.$request->ip();
        $max = max(1, (int) config($valid ? 'saas.signed_api.valid_per_minute' : 'saas.signed_api.invalid_per_minute'));

        if ($this->limiter->tooManyAttempts($key, $max)) {
            return new JsonResponse(['error' => 'too_many_requests'], 429, ['Retry-After' => (string) $this->limiter->availableIn($key)]);
        }
        $this->limiter->hit($key, 60);

        return $next($request);
    }
}
