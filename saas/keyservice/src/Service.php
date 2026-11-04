<?php

declare(strict_types=1);

namespace KeyService;

use Closure;
use JsonException;
use Throwable;

/**
 * Servizio chiavi: custodisce la chiave principale e cifra o decifra le chiavi dati delle associazioni.
 * Gira in un account separato dal SaaS: un dump del database del SaaS, da solo, non apre nessun dato.
 *
 * Controlli, in quest'ordine: metodo, indirizzo ammesso, firma, ora, nonce non ripetuto, contenuto.
 * Ogni operazione finisce nel registro (associazione, operazione, indirizzo), mai le chiavi.
 */
final readonly class Service
{
    /**
     * @param  list<string>  $allowedIps
     * @param  Closure(string): void  $log
     */
    public function __construct(
        private KeyWrap $wrap,
        private string $clientPublicKey,
        private array $allowedIps,
        private NonceStore $nonces,
        private Closure $log,
    ) {}

    /** @return array{status: int, body: array<string, string>} */
    public function handle(string $method, string $body, ?string $signature, string $ip, int $now): array
    {
        if ($method !== 'POST') {
            return self::error(405, 'metodo non ammesso');
        }
        if (! in_array($ip, $this->allowedIps, true)) {
            ($this->log)("rifiutato ip={$ip} motivo=indirizzo");

            return self::error(403, 'indirizzo non ammesso');
        }
        if ($signature === null || ! Protocol::verify($body, $signature, $this->clientPublicKey)) {
            ($this->log)("rifiutato ip={$ip} motivo=firma");

            return self::error(401, 'firma non valida');
        }

        try {
            $request = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::error(400, 'richiesta non valida');
        }
        if (! is_array($request)) {
            return self::error(400, 'richiesta non valida');
        }

        $ts = $request['ts'] ?? null;
        $nonce = $request['nonce'] ?? null;
        if (! is_int($ts) || abs($now - $ts) > Protocol::MAX_SKEW_SECONDS) {
            return self::error(401, 'richiesta scaduta');
        }
        if (! is_string($nonce) || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1 || $this->nonces->seen($nonce, 2 * Protocol::MAX_SKEW_SECONDS)) {
            ($this->log)("rifiutato ip={$ip} motivo=nonce");

            return self::error(401, 'richiesta ripetuta');
        }

        $op = $request['op'] ?? null;
        $orgId = $request['org_id'] ?? null;
        $version = $request['version'] ?? null;
        if (! is_string($orgId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $orgId) !== 1
            || ! is_int($version) || $version < 1) {
            return self::error(400, 'richiesta non valida');
        }

        try {
            $result = match ($op) {
                'wrap' => ['wrapped' => base64_encode($this->wrap->wrap($orgId, $version, self::binary($request['key'] ?? null)))],
                'unwrap' => ['key' => base64_encode($this->wrap->unwrap($orgId, $version, self::binary($request['wrapped'] ?? null)))],
                default => null,
            };
        } catch (Throwable) {
            ($this->log)("errore ip={$ip} op={$op} org={$orgId} v={$version}");

            return self::error(422, 'operazione non riuscita');
        }

        if ($result === null) {
            return self::error(400, 'operazione sconosciuta');
        }

        ($this->log)("ok ip={$ip} op={$op} org={$orgId} v={$version}");

        return ['status' => 200, 'body' => $result];
    }

    private static function binary(mixed $value): string
    {
        $raw = is_string($value) ? base64_decode($value, true) : false;

        return $raw === false ? '' : $raw;
    }

    /** @return array{status: int, body: array<string, string>} */
    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => $message]];
    }
}
