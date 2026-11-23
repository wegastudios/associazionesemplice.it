<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Cifratura\Contracts\OrgBatch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use KeyService\Protocol;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Servizio chiavi su un account separato: richieste firmate con Ed25519, solo HTTPS.
 *
 * Un secondo tentativo solo per errori di connessione e risposte 5xx: un rifiuto (4xx) è una risposta, e ripeterlo non
 * lo cambia. Con l'interruttore, dopo un servizio irraggiungibile o in errore le richieste dei 30 secondi successivi
 * falliscono subito, in tutti i processi. Durante il giro di un comando su tutte le associazioni ($batch) le richieste
 * vanno sulla corsia dei comandi.
 */
final readonly class RemoteKeyVault implements KeyVault
{
    public function __construct(
        private Factory $http,
        private string $url,
        #[SensitiveParameter] private string $signingKey,
        private float $timeout,
        private ?KeyServiceBreaker $breaker = null,
        private ?OrgBatch $batch = null,
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
        if ($this->breaker?->isOpen() === true) {
            throw new RuntimeException('Servizio chiavi non disponibile (errore recente, nuovo tentativo tra poco).');
        }
        if ($this->batch?->active() === true) {
            $payload['lane'] = Protocol::LANE_BATCH;
        }

        $body = Protocol::body($payload, Date::now()->getTimestamp());

        try {
            $response = $this->http
                ->connectTimeout(1)
                ->timeout($this->timeout)
                ->retry(2, 150, self::retryable(...), false)
                ->withHeaders([Protocol::SIGNATURE_HEADER => Protocol::sign($body, $this->signingKey)])
                ->withBody($body, 'application/json')
                ->post($this->url);
        } catch (ConnectionException $e) {
            $this->breaker?->trip();

            throw $e;
        }

        if ($response->serverError()) {
            $this->breaker?->trip();
        }

        $value = $response->successful() ? $response->json($field) : null;
        $raw = is_string($value) ? base64_decode($value, true) : false;

        if ($raw === false) {
            // Mai il corpo della risposta nell'errore: potrebbe contenere materiale delle chiavi.
            throw new RuntimeException("Servizio chiavi non disponibile (HTTP {$response->status()}).");
        }

        return $raw;
    }

    /** Si ritenta solo un errore di connessione o una risposta 5xx. */
    private static function retryable(Throwable $e): bool
    {
        return $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError());
    }
}
