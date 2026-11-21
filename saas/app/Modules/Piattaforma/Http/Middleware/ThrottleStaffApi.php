<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Middleware;

use App\Modules\Piattaforma\Identity\StaffIdentityConfig;
use App\Modules\Portale\Contracts\SignedRequestThrottle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Limite dell'API dello staff dopo la verifica della firma (due secchi: firme valide e non valide). */
final readonly class ThrottleStaffApi
{
    public function __construct(private SignedRequestThrottle $throttle, private StaffIdentityConfig $config) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $keys = [];
        foreach ($this->config->areaKeys() as $kid => $key) {
            $keys[$kid] = $key['x'];
        }

        return $this->throttle->handle($request, $next, 'staff', $keys);
    }
}
