<?php

declare(strict_types=1);

namespace App\Modules\Portale\Http\Controllers;

use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/catalogo/v1 (CONTRATTO_PORTALE.md §2): il catalogo pubblicato, senza dati personali. Il corpo è identico
 * finché non cambia la revisione; la firma (intestazione X-Catalog-Signature) si rifà a ogni risposta sugli stessi byte.
 */
final readonly class CatalogController
{
    public function __construct(private Catalog $catalog, private PortalConfig $config) {}

    public function __invoke(Request $request): Response
    {
        $published = $this->catalog->published();
        $body = $published->json();
        $hash = hash('sha256', $body);
        $headers = [
            'ETag' => '"'.$hash.'"',
            'Cache-Control' => 'public, max-age=300',
            'X-Catalog-Signature' => $this->signature($published->rev, $hash),
        ];

        if (in_array('"'.$hash.'"', array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return new Response('', 304, $headers);
        }

        return new Response($body, 200, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    private function signature(int $rev, string $hash): string
    {
        $now = time();
        $seed = $this->config->signingSeed();

        return EdDsaJwt::sign([
            'iss' => $this->config->saasUrl(),
            'aud' => $this->config->portalUrl(),
            'typ' => 'catalog',
            'iat' => $now,
            'exp' => $now + 600,
            'jti' => bin2hex(random_bytes(8)),
            'rev' => $rev,
            'sha256' => $hash,
        ], $seed);
    }
}
