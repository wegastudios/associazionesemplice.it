<?php

declare(strict_types=1);

namespace App\Modules\Portale\Http\Middleware;

use App\Modules\Portale\Contracts\SignedRequestThrottle;
use App\Modules\Portale\PortalConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Limite della rotta del PHP web dopo la verifica della firma: ambito proprio, due secchi come le altre API firmate. */
final readonly class ThrottleRuntimeApi
{
    public function __construct(private SignedRequestThrottle $throttle, private PortalConfig $config) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->throttle->handle($request, $next, 'runtime', $this->config->runtimeKeys());
    }
}
