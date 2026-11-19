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
        $body = $this->withLifecycle($published->document);
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

    /**
     * I giorni di conservazione hanno una sola fonte, la configurazione di questo gestionale (è lui a eseguire la
     * cancellazione): il catalogo li espone in `terms.lifecycle` e il portale li legge da qui, senza tenerne una copia.
     * `retention_days`: l'ultimo giorno in cui i dati ci sono è la scadenza più questi giorni. `trial_purge_days`: lo
     * stesso per la prova, dalla sua fine. Un vecchio `terms.trial.purge_days` salvato nel catalogo non si espone più.
     *
     * @param  array<string, mixed>  $document
     */
    private function withLifecycle(array $document): string
    {
        $terms = is_array($document['terms'] ?? null) ? $document['terms'] : [];
        if (is_array($terms['trial'] ?? null)) {
            unset($terms['trial']['purge_days']);
        }
        $terms['lifecycle'] = ['retention_days' => $this->config->retentionDays(), 'trial_purge_days' => $this->config->trialPurgeDays()];
        $document['terms'] = $terms;

        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
