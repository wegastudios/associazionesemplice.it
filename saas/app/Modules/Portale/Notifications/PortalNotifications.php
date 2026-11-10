<?php

declare(strict_types=1);

namespace App\Modules\Portale\Notifications;

use App\Modules\Portale\Contracts\PortalNotifier;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Invia al portale gli avvisi firmati dal SaaS. */
final readonly class PortalNotifications implements PortalNotifier
{
    public function __construct(private PortalConfig $config) {}

    public function catalogChanged(int $rev): bool
    {
        try {
            $now = time();
            $token = EdDsaJwt::sign([
                'iss' => $this->config->saasUrl(),
                'aud' => $this->config->portalUrl(),
                'typ' => 'catalog_changed',
                'iat' => $now,
                'exp' => $now + 300,
                'jti' => bin2hex(random_bytes(8)),
                'rev' => $rev,
            ], $this->config->signingSeed());

            return Http::timeout(5)->acceptJson()->post($this->config->portalUrl().'/api/saas/v1/catalog-changed', ['token' => $token])->successful();
        } catch (Throwable $e) {
            // Il portale ricarica comunque il catalogo ogni 10 minuti: l'avviso è solo un'accelerazione.
            Log::warning('Avviso catalog_changed non consegnato: '.$e::class);

            return false;
        }
    }
}
