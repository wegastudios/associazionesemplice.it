<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pagina di chi lavora solo per associazioni sospese: dice cosa è successo e porta al pagamento sul portale.
 * Non legge nessun dato dell'associazione e non offre esportazioni.
 */
final readonly class SuspendedController
{
    public function __construct(private Memberships $memberships) {}

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Orgs/Sospesa', [
            'orgs' => $this->memberships->suspendedNames($user->id),
            'portalUrl' => rtrim((string) config('saas.portal.url'), '/').'/account',
        ]);
    }
}
