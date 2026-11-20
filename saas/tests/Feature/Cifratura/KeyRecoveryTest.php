<?php

declare(strict_types=1);

use App\Modules\Cifratura\Keys\LocalKeyVault;
use App\Modules\Cifratura\Keys\RecoveryFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use KeyService\Config;
use Tests\Fixtures\FakeKeyService;

/*
 * Copia di recupero delle chiavi: si crea con una frase scritta in un prompt nascosto, si riapre solo con quella, non
 * mostra mai segreti a video né nel registro, sta su disco con permessi 600.
 */

const RECOVERY_FEATURE_PASSPHRASE = 'tramonto sul lago di garda in novembre';

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/as-recovery-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0700);
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    });
});

afterEach(function (): void {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    @rmdir($this->dir);
});

/** Crea la copia rispondendo ai prompt; restituisce il percorso. */
function createRecoveryCopy(object $test, string $name = 'copia.askr'): string
{
    $path = $test->dir.'/'.$name;
    $test->artisan('saas:keys:recovery', ['file' => $path])
        ->expectsQuestion('Frase segreta', RECOVERY_FEATURE_PASSPHRASE)
        ->expectsQuestion('Ripeti la frase segreta', RECOVERY_FEATURE_PASSPHRASE)
        ->doesntExpectOutputToContain(LocalKeyVault::TEST_MASTER_KEY)
        ->doesntExpectOutputToContain(RECOVERY_FEATURE_PASSPHRASE)
        ->expectsOutputToContain('permessi 600')
        ->assertSuccessful();

    return $path;
}

it('crea la copia con permessi 600, senza segreti a video, nel file o nel registro', function (): void {
    $path = createRecoveryCopy($this);

    expect(is_file($path))->toBeTrue()
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and(file_get_contents($path))->not->toContain(LocalKeyVault::TEST_MASTER_KEY)
        ->and(file_get_contents($path))->not->toContain(base64_decode(LocalKeyVault::TEST_MASTER_KEY))
        ->and(implode("\n", $this->logged))->not->toContain(LocalKeyVault::TEST_MASTER_KEY)
        ->and(implode("\n", $this->logged))->not->toContain(RECOVERY_FEATURE_PASSPHRASE);

    $row = DB::table('core.platform_audit')->where('action', 'keys.recovery_created')->first();
    expect($row)->not->toBeNull()
        ->and((string) $row->details)->not->toContain(LocalKeyVault::TEST_MASTER_KEY)
        ->and(RecoveryFile::open((string) file_get_contents($path), RECOVERY_FEATURE_PASSPHRASE)['items']['saas_master_key'])->toBe(LocalKeyVault::TEST_MASTER_KEY);
});

it('rifiuta frasi corte o diverse e non scrive nulla', function (): void {
    $path = $this->dir.'/copia.askr';

    $this->artisan('saas:keys:recovery', ['file' => $path])
        ->expectsQuestion('Frase segreta', 'corta')
        ->expectsOutputToContain('almeno 16 caratteri')
        ->assertFailed();

    $this->artisan('saas:keys:recovery', ['file' => $path])
        ->expectsQuestion('Frase segreta', RECOVERY_FEATURE_PASSPHRASE)
        ->expectsQuestion('Ripeti la frase segreta', RECOVERY_FEATURE_PASSPHRASE.' diversa')
        ->expectsOutputToContain('non coincidono')
        ->assertFailed();

    expect(file_exists($path))->toBeFalse();
});

it('non scrive nella cartella web o nella versione installata e non sovrascrive', function (): void {
    $this->artisan('saas:keys:recovery', ['file' => public_path('copia.askr')])->expectsOutputToContain('fuori dalla cartella web')->assertFailed();
    $this->artisan('saas:keys:recovery', ['file' => storage_path('copia.askr')])->expectsOutputToContain('fuori dalla cartella web')->assertFailed();
    expect(file_exists(public_path('copia.askr')))->toBeFalse();

    $path = createRecoveryCopy($this);
    $before = file_get_contents($path);
    $this->artisan('saas:keys:recovery', ['file' => $path])->expectsOutputToContain('esiste già')->assertFailed();
    expect(file_get_contents($path))->toBe($before);
});

it('la verifica apre la copia e riapre le chiavi dati delle associazioni', function (): void {
    $this->createOrg('Polisportiva Alfa');
    $this->createOrg('Circolo Beta');
    $path = createRecoveryCopy($this);

    $this->artisan('saas:keys:recovery-check', ['file' => $path])
        ->expectsQuestion('Frase segreta', RECOVERY_FEATURE_PASSPHRASE)
        ->expectsOutputToContain('chiave principale locale uguale a quella del file d\'ambiente: sì')
        ->expectsOutputToContain('la copia riapre tutte le')
        ->doesntExpectOutputToContain(LocalKeyVault::TEST_MASTER_KEY)
        ->doesntExpectOutputToContain(RECOVERY_FEATURE_PASSPHRASE)
        ->assertSuccessful();
});

it('la verifica rifiuta la frase sbagliata e il file alterato', function (): void {
    $path = createRecoveryCopy($this);

    $this->artisan('saas:keys:recovery-check', ['file' => $path])
        ->expectsQuestion('Frase segreta', 'una frase lunga ma sbagliata del tutto')
        ->expectsOutputToContain('Frase segreta sbagliata o copia di recupero danneggiata')
        ->doesntExpectOutputToContain(LocalKeyVault::TEST_MASTER_KEY)
        ->assertFailed();

    $data = (string) file_get_contents($path);
    $data[strlen($data) - 1] = chr(ord($data[strlen($data) - 1]) ^ 0xFF);
    file_put_contents($path, $data);
    $this->artisan('saas:keys:recovery-check', ['file' => $path])
        ->expectsQuestion('Frase segreta', RECOVERY_FEATURE_PASSPHRASE)
        ->expectsOutputToContain('Frase segreta sbagliata o copia di recupero danneggiata')
        ->assertFailed();
});

it('la verifica fallisce se la copia non apre le chiavi dati salvate', function (): void {
    $this->createOrg('Polisportiva Alfa');

    // Una copia con un'altra chiave principale: le chiavi dati salvate sono avvolte con quella del file d'ambiente.
    $path = $this->dir.'/altra.askr';
    file_put_contents($path, RecoveryFile::seal(['saas_master_key' => base64_encode(random_bytes(32))], RECOVERY_FEATURE_PASSPHRASE));

    $this->artisan('saas:keys:recovery-check', ['file' => $path])
        ->expectsQuestion('Frase segreta', RECOVERY_FEATURE_PASSPHRASE)
        ->expectsOutputToContain('chiave principale locale uguale a quella del file d\'ambiente: no')
        ->expectsOutputToContain('la copia non riapre')
        ->assertFailed();
});

it('con --con-servizio salva la chiave del servizio solo se è quella vera, e la ripristina in config.ini', function (): void {
    $service = (new FakeKeyService)->install();
    $path = $this->dir.'/servizio.askr';

    $this->artisan('saas:keys:recovery', ['file' => $path, '--con-servizio' => true])
        ->expectsQuestion('Chiave principale del servizio chiavi (master_key di config.ini)', base64_encode(random_bytes(32)))
        ->expectsOutputToContain('non è quella che usa il servizio chiavi')
        ->assertFailed();
    expect(file_exists($path))->toBeFalse();

    $this->artisan('saas:keys:recovery', ['file' => $path, '--con-servizio' => true])
        ->expectsQuestion('Chiave principale del servizio chiavi (master_key di config.ini)', base64_encode($service->masterKey))
        ->expectsQuestion('Indirizzi del SaaS ammessi dal servizio (allowed_ips, separati da virgole)', '203.0.113.7')
        ->expectsQuestion('Frase segreta', RECOVERY_FEATURE_PASSPHRASE)
        ->expectsQuestion('Ripeti la frase segreta', RECOVERY_FEATURE_PASSPHRASE)
        ->doesntExpectOutputToContain(base64_encode($service->masterKey))
        ->doesntExpectOutputToContain(base64_encode($service->signingKey))
        ->assertSuccessful();

    $restore = $this->dir.'/ripristino';
    mkdir($restore, 0700);
    $this->artisan('saas:keys:recovery-check', ['file' => $path, '--ripristina' => $restore])
        ->expectsQuestion('Frase segreta', RECOVERY_FEATURE_PASSPHRASE)
        ->expectsOutputToContain('chiave di firma e chiave pubblica del servizio sono una coppia')
        ->expectsOutputToContain('il servizio chiavi usa la chiave principale della copia')
        ->doesntExpectOutputToContain(base64_encode($service->masterKey))
        ->assertSuccessful();

    expect(fileperms($restore.'/config.ini') & 0777)->toBe(0600)
        ->and(fileperms($restore.'/saas.env') & 0777)->toBe(0600);
    $config = Config::load($restore.'/config.ini', $restore);
    expect($config->masterKey)->toBe($service->masterKey)
        ->and($config->clientPublicKey)->toBe(sodium_crypto_sign_publickey_from_secretkey($service->signingKey))
        ->and($config->allowedIps)->toBe(['203.0.113.7'])
        ->and((string) file_get_contents($restore.'/saas.env'))->toContain('SAAS_KEY_SERVICE_URL='.FakeKeyService::URL);

    unlink($restore.'/config.ini');
    unlink($restore.'/saas.env');
    rmdir($restore);
});
