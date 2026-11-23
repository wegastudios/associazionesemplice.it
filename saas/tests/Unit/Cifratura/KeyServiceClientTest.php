<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\OrgBatch;
use App\Modules\Cifratura\Keys\KeyServiceBreaker;
use App\Modules\Cifratura\Keys\RemoteKeyVault;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use KeyService\Config;
use KeyService\KeyWrap;
use KeyService\MemoryNonceStore;
use KeyService\MemoryRateCounter;
use KeyService\Protocol;
use KeyService\Service;

/*
 * Il SaaS verso il servizio chiavi: niente secondo tentativo sui rifiuti, interruttore «servizio giù» condiviso tra i
 * processi, corsia e pausa dei comandi che girano su tutte le associazioni.
 */

const CLIENT_URL = 'https://chiavi.example.test/';
const CLIENT_ORG = '01a1a5f6-7d73-70ca-9522-e1bb5deeb707';

/** Un processo: interruttore suo (sulla cache condivisa dell'applicazione) e, se c'è, il giro dei comandi. */
function clientVault(string $secret, ?OrgBatch $batch = null): RemoteKeyVault
{
    return new RemoteKeyVault(app(Factory::class), CLIENT_URL, $secret, 3, new KeyServiceBreaker(app(Repository::class)), $batch);
}

/**
 * Il servizio vero con le soglie di partenza della configurazione, e un giro su $orgs associazioni simulate con due
 * aperture ciascuna.
 *
 * @return array{statuses: list<int>, lanes: list<string>, log: list<string>, failed: int, seconds: int}
 */
function batchRun(int $orgs, int $pauseMs): array
{
    $wrap = new KeyWrap(random_bytes(32));
    $pair = sodium_crypto_sign_keypair();
    $run = ['statuses' => [], 'lanes' => [], 'log' => [], 'failed' => 0, 'seconds' => 0];
    $service = new Service(
        $wrap,
        sodium_crypto_sign_publickey($pair),
        ['127.0.0.1'],
        new MemoryNonceStore,
        function (string $line) use (&$run): void {
            $run['log'][] = $line;
        },
        new MemoryRateCounter,
        Config::UNWRAP_ALARM,
        Config::UNWRAP_LIMIT,
        null,
        Config::BATCH_ALARM,
        Config::BATCH_LIMIT,
        Config::TOTAL_ALARM,
        Config::TOTAL_LIMIT,
    );
    Http::fake([CLIENT_URL.'*' => function (Request $request) use ($service, &$run) {
        $body = json_decode($request->body(), true);
        $run['lanes'][] = is_array($body) && is_string($body['lane'] ?? null) ? $body['lane'] : 'org';
        $result = $service->handle('POST', $request->body(), $request->header(Protocol::SIGNATURE_HEADER)[0] ?? null, '127.0.0.1', Date::now()->getTimestamp());
        $run['statuses'][] = $result['status'];

        return Http::response($result['body'], $result['status']);
    }]);

    $wrapped = [];
    for ($i = 0; $i < $orgs; $i++) {
        $id = (string) Str::uuid7();
        $wrapped[$id] = $wrap->wrap($id, 1, random_bytes(32));
    }

    $batch = new OrgBatch($pauseMs);
    $vault = clientVault(sodium_crypto_sign_secretkey($pair), $batch);
    $start = Date::now()->getTimestamp();
    foreach ($batch->paced(array_keys($wrapped)) as $id) {
        // Due aperture per associazione: prima versione (sigillo del registro) e versione in uso.
        for ($j = 0; $j < 2; $j++) {
            try {
                $vault->unwrap($id, 1, $wrapped[$id]);
            } catch (RuntimeException) {
                $run['failed']++;
            }
        }
    }
    $run['seconds'] = Date::now()->getTimestamp() - $start;
    expect($batch->active())->toBeFalse();

    return $run;
}

beforeEach(function (): void {
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo(Date::parse('2026-11-22 03:00:00'));
    $this->secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $this->calls = 0;
});

afterEach(function (): void {
    Sleep::fake(false);
});

it('non ripete le risposte 4xx e non le prende per un servizio giù', function (int $status): void {
    Http::fake([CLIENT_URL.'*' => function () use ($status) {
        $this->calls++;

        return Http::response(['error' => 'rifiutato'], $status);
    }]);
    $vault = clientVault($this->secret);

    expect(fn () => $vault->unwrap(CLIENT_ORG, 1, 'x'))->toThrow(RuntimeException::class, "HTTP {$status}")
        ->and($this->calls)->toBe(1);

    // Nessun interruttore: la richiesta dopo arriva di nuovo al servizio.
    expect(fn () => $vault->unwrap(CLIENT_ORG, 1, 'x'))->toThrow(RuntimeException::class, "HTTP {$status}")
        ->and($this->calls)->toBe(2);
})->with([400, 401, 403, 422, 429]);

it('servizio in errore: un secondo tentativo, poi per 30 secondi errore subito in tutti i processi', function (): void {
    $down = true;
    Http::fake([CLIENT_URL.'*' => function () use (&$down) {
        $this->calls++;

        return $down ? Http::response(['error' => 'non disponibile'], 503) : Http::response(['key' => base64_encode('chiave')]);
    }]);
    $vault = clientVault($this->secret);

    expect(fn () => $vault->unwrap(CLIENT_ORG, 1, 'x'))->toThrow(RuntimeException::class, 'HTTP 503')
        ->and($this->calls)->toBe(2);

    $down = false;
    $start = microtime(true);
    expect(fn () => $vault->unwrap(CLIENT_ORG, 1, 'x'))->toThrow(RuntimeException::class, 'errore recente')
        // Un altro processo (interruttore nuovo) vede lo stato dalla cache dell'applicazione, senza APCu.
        ->and(fn () => clientVault($this->secret)->unwrap(CLIENT_ORG, 1, 'x'))->toThrow(RuntimeException::class, 'errore recente')
        ->and($this->calls)->toBe(2)
        ->and(microtime(true) - $start)->toBeLessThan(1.0);

    $this->travel(29)->seconds();
    expect(fn () => clientVault($this->secret)->unwrap(CLIENT_ORG, 1, 'x'))->toThrow(RuntimeException::class, 'errore recente')
        ->and($this->calls)->toBe(2);

    $this->travel(2)->seconds();
    expect($vault->unwrap(CLIENT_ORG, 1, 'x'))->toBe('chiave')
        ->and($this->calls)->toBe(3);
});

it('servizio irraggiungibile: errore subito per 30 secondi dopo il primo fallimento', function (): void {
    Http::fake([CLIENT_URL.'*' => function () {
        $this->calls++;

        throw new ConnectionException('irraggiungibile');
    }]);
    $vault = clientVault($this->secret);

    expect(fn () => $vault->wrap(CLIENT_ORG, 1, random_bytes(32)))->toThrow(ConnectionException::class)
        ->and($this->calls)->toBe(2);

    expect(fn () => clientVault($this->secret)->unwrap(CLIENT_ORG, 1, 'x'))->toThrow(RuntimeException::class, 'errore recente')
        ->and($this->calls)->toBe(2);

    $this->travel(31)->seconds();
    expect(fn () => $vault->unwrap(CLIENT_ORG, 1, 'x'))->toThrow(ConnectionException::class)
        ->and($this->calls)->toBe(4);
});

it('fuori da un giro dei comandi le richieste vanno sulla corsia dell\'associazione', function (): void {
    $lanes = [];
    Http::fake([CLIENT_URL.'*' => function (Request $request) use (&$lanes) {
        $body = json_decode($request->body(), true);
        $lanes[] = is_array($body) && array_key_exists('lane', $body) ? $body['lane'] : null;

        return Http::response(['key' => base64_encode('chiave')]);
    }]);
    $batch = new OrgBatch(0);
    $vault = clientVault($this->secret, $batch);

    $vault->unwrap(CLIENT_ORG, 1, 'x');
    foreach ($batch->paced([CLIENT_ORG]) as $id) {
        $vault->unwrap($id, 1, 'x');
    }
    $vault->unwrap(CLIENT_ORG, 1, 'x');

    expect($lanes)->toBe([null, 'batch', null]);
});

it('un comando su 300 associazioni, con la pausa, resta sotto il tetto della corsia dei comandi', function (): void {
    $run = batchRun(300, 500);

    expect($run['statuses'])->toHaveCount(600)
        ->and(array_values(array_unique($run['statuses'])))->toBe([200])
        ->and(array_values(array_unique($run['lanes'])))->toBe(['batch'])
        ->and($run['failed'])->toBe(0)
        ->and(implode("\n", $run['log']))->not->toContain('ALLARME')
        // 299 pause da mezzo secondo: al massimo 120 associazioni (240 aperture) in un minuto.
        ->and($run['seconds'])->toBeGreaterThanOrEqual(149);
});

it('senza pausa lo stesso giro supera il tetto: il tetto resta', function (): void {
    $run = batchRun(300, 0);

    expect(array_count_values($run['statuses']))->toBe([200 => Config::BATCH_LIMIT, 429 => 600 - Config::BATCH_LIMIT])
        ->and($run['failed'])->toBe(600 - Config::BATCH_LIMIT)
        ->and(implode("\n", $run['log']))->toContain('ALLARME aperture=250 al minuto corsia=comandi');
});
