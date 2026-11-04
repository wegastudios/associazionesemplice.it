<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use App\Modules\Cifratura\Contracts\KeyVault;
use Illuminate\Http\Client\Factory;
use KeyService\Protocol;
use RuntimeException;
use SensitiveParameter;

/** Servizio chiavi su un account separato: richieste firmate con Ed25519, solo HTTPS. */
final readonly class RemoteKeyVault implements KeyVault
{
    public function __construct(
        private Factory $http,
        private string $url,
        #[SensitiveParameter] private string $signingKey,
        private float $timeout,
    ) {
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://127.0.0.1')) {
            throw new RuntimeException('Il servizio chiavi si raggiunge solo in HTTPS.');
        }
    }

    public function wrap(string $orgId, int $version, #[SensitiveParameter] string $dataKey): string
    {
        return $this->call(['op' => 'wrap', 'org_id' => $orgId, 'version' => $version, 'key' => base64_encode($dataKey)], 'wrapped');
    }

    public function unwrap(string $orgId, int $version, string $wrapped): string
    {
        return $this->call(['op' => 'unwrap', 'org_id' => $orgId, 'version' => $version, 'wrapped' => base64_encode($wrapped)], 'key');
    }

    /** @param  array<string, int|string>  $payload */
    private function call(array $payload, string $field): string
    {
        $body = Protocol::body($payload, time());

        $response = $this->http
            ->timeout($this->timeout)
            ->withHeaders([Protocol::SIGNATURE_HEADER => Protocol::sign($body, $this->signingKey)])
            ->withBody($body, 'application/json')
            ->post($this->url);

        $value = $response->successful() ? $response->json($field) : null;
        $raw = is_string($value) ? base64_decode($value, true) : false;

        if ($raw === false) {
            // Mai il corpo della risposta nell'errore: potrebbe contenere materiale delle chiavi.
            throw new RuntimeException("Servizio chiavi non disponibile (HTTP {$response->status()}).");
        }

        return $raw;
    }
}
