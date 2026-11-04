<?php

declare(strict_types=1);

namespace App\Modules\Portale\Http\Controllers;

use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * «Gestisci abbonamento»: il titolare passa al portale già riconosciuto, con un token firmato dal SaaS che vale
 * 60 secondi e una volta sola (il portale controlla il jti). Solo il titolare (permesso org.subscription).
 */
final readonly class SubscriptionSsoController
{
    public function __construct(
        private PortalConfig $config,
        private CurrentOperator $operator,
        private OrgDirectory $orgs,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $operator = $this->operator->get() ?? abort(403);
        $user = $request->user();
        $email = $user instanceof Model ? $user->getAttribute('email') : null;
        if (! is_string($email)) {
            abort(401);
        }
        $ref = $this->orgs->portalRef($operator->orgId) ?? abort(404, 'Questa associazione non ha un abbonamento sul portale.');
        $now = time();

        $token = EdDsaJwt::sign([
            'iss' => $this->config->saasUrl(),
            'aud' => $this->config->portalUrl(),
            'typ' => 'sso',
            'sub' => $email,
            'org_id' => $operator->orgId,
            'ref' => $ref,
            'role' => $operator->role->value,
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => Str::uuid7()->toString(),
        ], $this->config->signingSeed());

        return redirect()->away($this->config->portalUrl().'/saas/accedi?t='.$token);
    }
}
