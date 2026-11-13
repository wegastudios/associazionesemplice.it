<?php

declare(strict_types=1);

namespace App\Modules\Portale\Http\Middleware;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rotte di un pacchetto: `package:member_area`. Senza il pacchetto attivo nell'associazione corrente, 403. */
final readonly class RequirePackage
{
    public function __construct(
        private OrgContext $context,
        private Entitlements $entitlements,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $package): Response
    {
        $org = $this->context->require();

        if (! $this->entitlements->for($org->id)->has(Package::from($package))) {
            abort(403, 'Questa funzione fa parte di un pacchetto che la tua associazione non ha attivato. Il titolare può aggiungerlo da Gestione › Abbonamento e pacchetti.');
        }

        return $next($request);
    }
}
