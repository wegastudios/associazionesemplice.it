<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dopo il rilascio (H22): il SaaS chiede a se stesso, con un token firmato con la propria chiave, di azzerare OPcache e
 * la cache dei percorsi del PHP che serve le pagine (la riga di comando ha le sue, che non contano), poi fa poche
 * richieste di riscaldamento. Tutto «al meglio»: se qualcosa non risponde si avvisa e il rilascio prosegue, perché
 * con il controllo dei file ogni 2 secondi il codice nuovo arriva comunque.
 * La rotta non riceve parametri: il token ha tipo e destinatario propri, diversi da quelli del rapporto di sola lettura.
 */
final readonly class WebRuntimeReset
{
    public const AUDIENCE = 'associazionesemplice-saas-runtime-reset';

    public const TYPE = 'runtime_reset';

    /** Pagine che compilano le parti più usate: stato e catalogo pubblico. Nessuna con dati dei clienti e nessuna che apra una sessione. */
    public const WARM_PATHS = ['/up', '/api/catalogo/v1'];

    public function __construct(private PortalConfig $config) {}

    /**
     * Quello che fa la rotta, nel PHP web: azzera OPcache (se c'è e lo permette) e la cache dei percorsi dei file.
     *
     * @return array{opcache_reset: bool, realpath_cache_cleared: bool}
     */
    public static function perform(): array
    {
        $opcache = function_exists('opcache_reset') && @opcache_reset();
        clearstatcache(true);

        return ['opcache_reset' => $opcache, 'realpath_cache_cleared' => true];
    }

    /** @return array{opcache_reset: bool, realpath_cache_cleared: bool}|null l'esito del PHP web, o null se non raggiungibile */
    public function request(): ?array
    {
        try {
            $now = time();
            $token = EdDsaJwt::sign([
                'iss' => $this->config->saasUrl(),
                'aud' => self::AUDIENCE,
                'typ' => self::TYPE,
                'iat' => $now,
                'exp' => $now + 60,
                'jti' => bin2hex(random_bytes(8)),
            ], $this->config->signingSeed(), 'saas-');

            $response = Http::timeout(10)->withoutRedirecting()->acceptJson()->post($this->config->saasUrl().'/api/portale/v2/runtime/reset', ['token' => $token]);
            $body = $response->successful() ? $response->json() : null;

            if (! is_array($body) || ! is_bool($body['opcache_reset'] ?? null) || ! is_bool($body['realpath_cache_cleared'] ?? null)) {
                return null;
            }

            return ['opcache_reset' => $body['opcache_reset'], 'realpath_cache_cleared' => $body['realpath_cache_cleared']];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Le richieste di riscaldamento, una dopo l'altra: il primo passaggio compila e mette in OPcache il codice.
     *
     * @return array<string, int|null> percorso => codice della risposta (null se non ha risposto)
     */
    public function warm(): array
    {
        $out = [];
        foreach (self::WARM_PATHS as $path) {
            try {
                $out[$path] = Http::timeout(10)->withoutRedirecting()->get($this->config->saasUrl().$path)->status();
            } catch (Throwable) {
                $out[$path] = null;
            }
        }

        return $out;
    }
}
