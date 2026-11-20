<?php

declare(strict_types=1);

use KeyService\Config;
use KeyService\FileRateCounter;
use KeyService\KeyWrap;
use KeyService\MemoryNonceStore;
use KeyService\MemoryRateCounter;
use KeyService\NonceStore;
use KeyService\Protocol;
use KeyService\RotatingLog;
use KeyService\Service;

const HARD_ORG = '01a1a5f6-7d73-70ca-9522-e1bb5deeb707';

/**
 * @param  list<string>  $log
 * @param  list<string>  $alarms
 * @return array{0: Service, 1: string}
 */
function hardService(array &$log, array &$alarms, ?NonceStore $nonces = null, int $alarm = 0, int $limit = 0): array
{
    $pair = sodium_crypto_sign_keypair();
    $service = new Service(
        new KeyWrap(random_bytes(32)),
        sodium_crypto_sign_publickey($pair),
        ['10.0.0.5'],
        $nonces ?? new MemoryNonceStore,
        function (string $line) use (&$log): void {
            $log[] = $line;
        },
        new MemoryRateCounter,
        $alarm,
        $limit,
        function (string $message) use (&$alarms): void {
            $alarms[] = $message;
        },
    );

    return [$service, sodium_crypto_sign_secretkey($pair)];
}

function hardCall(Service $service, string $secret, array $payload, int $now): array
{
    $body = Protocol::body($payload, $now);

    return $service->handle('POST', $body, Protocol::sign($body, $secret), '10.0.0.5', $now);
}

it('risponde in modo generico a un errore imprevisto, senza percorsi', function (): void {
    $log = [];
    $alarms = [];
    $broken = new class implements NonceStore
    {
        public function seen(string $nonce, int $ttl): bool
        {
            throw new RuntimeException('mkdir(): Permission denied in /home/segreto/keyservice/storage/nonces');
        }
    };
    [$service, $secret] = hardService($log, $alarms, $broken);

    $result = hardCall($service, $secret, ['op' => 'unwrap', 'org_id' => HARD_ORG, 'version' => 1, 'wrapped' => 'eA=='], time());

    expect($result['status'])->toBe(500)
        ->and($result['body'])->toBe(['error' => 'errore interno'])
        ->and(json_encode($result))->not->toContain('segreto')
        ->and(implode("\n", $log))->toContain('errore-interno tipo=RuntimeException')
        ->not->toContain('segreto');
});

it('rifiuta le aperture oltre il limite al minuto e dà l\'allarme', function (): void {
    $log = [];
    $alarms = [];
    [$service, $secret] = hardService($log, $alarms, alarm: 3, limit: 5);
    $now = 1_800_000_000;
    $payload = ['op' => 'unwrap', 'org_id' => HARD_ORG, 'version' => 1, 'wrapped' => 'eA=='];

    $statuses = [];
    for ($i = 0; $i < 7; $i++) {
        $statuses[] = hardCall($service, $secret, $payload, $now)['status'];
    }

    // Le prime cinque arrivano all'operazione (422: chiave non valida), le altre sono respinte.
    expect($statuses)->toBe([422, 422, 422, 422, 422, 429, 429])
        ->and($alarms)->toHaveCount(2)
        ->and(implode("\n", $log))->toContain('ALLARME aperture=3', 'motivo=limite');

    // Il minuto dopo si riparte.
    expect(hardCall($service, $secret, $payload, $now + 60)['status'])->toBe(422);
});

it('non limita le operazioni di avvolgimento', function (): void {
    $log = [];
    $alarms = [];
    [$service, $secret] = hardService($log, $alarms, alarm: 1, limit: 1);

    for ($i = 0; $i < 3; $i++) {
        $result = hardCall($service, $secret, ['op' => 'wrap', 'org_id' => HARD_ORG, 'version' => 1, 'key' => base64_encode(random_bytes(32))], time());
        expect($result['status'])->toBe(200);
    }
    expect($alarms)->toBe([]);
});

it('il contatore su file conta per minuto', function (): void {
    $dir = sys_get_temp_dir().'/ks-rate-'.bin2hex(random_bytes(4));
    $counter = new FileRateCounter($dir);

    expect($counter->hit('unwrap', 120))->toBe(1)
        ->and($counter->hit('unwrap', 150))->toBe(2)
        ->and($counter->hit('unwrap', 181))->toBe(1)
        ->and(fn () => $counter->hit('../x', 1))->toThrow(InvalidArgumentException::class);

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('il registro passa al file successivo oltre il limite e ne tiene un numero fisso', function (): void {
    $dir = sys_get_temp_dir().'/ks-log-'.bin2hex(random_bytes(4));
    $file = $dir.'/keyservice.log';
    $log = new RotatingLog($file, 200, 2);

    for ($i = 0; $i < 40; $i++) {
        $log->write("riga {$i} ".str_repeat('x', 30));
    }

    $files = array_map('basename', glob($dir.'/keyservice.log*') ?: []);
    sort($files);

    expect($files)->toBe(['keyservice.log', 'keyservice.log.1', 'keyservice.log.2', 'keyservice.log.lock'])
        ->and((int) filesize($file))->toBeLessThan(400)
        ->and((string) file_get_contents($file))->toContain('riga 39');

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('il registro toglie i caratteri di controllo e taglia le righe lunghe', function (): void {
    $dir = sys_get_temp_dir().'/ks-log-'.bin2hex(random_bytes(4));
    $file = $dir.'/keyservice.log';
    (new RotatingLog($file))->write("ok\nfalsa riga\r".str_repeat('y', 1000));

    $lines = file($file) ?: [];
    expect($lines)->toHaveCount(1)
        ->and(strlen($lines[0]))->toBeLessThan(450);

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('legge le soglie dalla configurazione e rifiuta valori non validi', function (): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'ks');
    $pub = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    $base = 'master_key = "'.base64_encode(random_bytes(32))."\"\nclient_public_key = \"{$pub}\"\nallowed_ips = \"10.0.0.5\"\n";

    file_put_contents($file, $base);
    $default = Config::load($file, '/srv/ks');
    expect($default->unwrapAlarm)->toBe(100)
        ->and($default->unwrapLimit)->toBe(200)
        ->and($default->logMaxBytes)->toBe(5242880)
        ->and($default->rateDir)->toBe('/srv/ks/storage/rate');

    file_put_contents($file, $base."unwrap_alarm_per_minute = 10\nunwrap_limit_per_minute = 20\nalarm_email = \"a@example.org\"\n");
    $custom = Config::load($file, '/srv/ks');
    expect($custom->unwrapAlarm)->toBe(10)
        ->and($custom->unwrapLimit)->toBe(20)
        ->and($custom->alarmEmail)->toBe('a@example.org');

    file_put_contents($file, $base."unwrap_limit_per_minute = \"molte\"\n");
    expect(fn () => Config::load($file, '/srv/ks'))->toThrow(RuntimeException::class);

    file_put_contents($file, $base."alarm_email = \"non un'email\"\n");
    expect(fn () => Config::load($file, '/srv/ks'))->toThrow(RuntimeException::class);

    unlink($file);
});
