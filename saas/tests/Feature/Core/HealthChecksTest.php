<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Cifratura\Keys\LocalKeyVault;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Platform\PlatformConfig;
use App\Modules\Core\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('controlla ruolo, tabelle, permessi e connessione dell\'applicazione sul database', function (): void {
    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('ok    ruolo dell\'applicazione')
        ->expectsOutputToContain('ok    tabelle delle associazioni')
        ->expectsOutputToContain('ok    permessi dell\'applicazione')
        ->expectsOutputToContain('ok    connessione senza pooler')
        ->assertSuccessful();
});

it('in produzione la chiave principale locale fa fallire il controllo di salute con SAAS_HEALTH_STRICT', function (): void {
    app(KeyVault::class);
    config(['app.env' => 'production', 'saas.crypto.vault' => 'local', 'mail.default' => 'smtp', 'saas.health_strict' => true]);

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('chiave principale locale')
        ->assertFailed();
});

it('senza SAAS_HEALTH_STRICT la chiave principale locale in produzione è solo un avviso', function (): void {
    app(KeyVault::class);
    config(['app.env' => 'production', 'saas.crypto.vault' => 'local', 'mail.default' => 'smtp', 'saas.health_strict' => false]);

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('AVVISO chiave principale in produzione')
        ->assertSuccessful();
});

it('in produzione la posta nel log o in memoria fa fallire il controllo di salute', function (string $mailer): void {
    app(KeyVault::class);
    config(['app.env' => 'production', 'saas.crypto.vault' => 'remote', 'mail.default' => $mailer]);

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('posta non configurata')
        ->assertFailed();
})->with(['log', 'array']);

it('fuori dalla produzione la posta nel log resta una nota', function (): void {
    config(['mail.default' => 'log']);

    $this->artisan('saas:health', ['--skip-migrations' => true])->assertSuccessful();
});

it('con il disco dei backup SFTP l\'impronta dell\'host è obbligatoria', function (): void {
    config(['filesystems.disks.backups' => ['driver' => 'sftp', 'host' => 'backup.example.org', 'hostFingerprint' => '']]);

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('impronta dell\'host SFTP')
        ->assertFailed();
});

it('segna la piattaforma «di prova» se in produzione la chiave principale è locale', function (): void {
    config(['app.env' => 'production', 'saas.crypto.vault' => 'local']);

    $warnings = app(PlatformConfig::class)->apply();

    expect(config('saas.crypto.trial'))->toBeTrue()
        ->and(implode(' ', $warnings))->toContain('Chiave principale locale in produzione');
});

it('non segna «di prova» la chiave locale fuori dalla produzione', function (): void {
    config(['app.env' => 'testing', 'saas.crypto.vault' => 'local']);

    app(PlatformConfig::class)->apply();

    expect(config('saas.crypto.trial'))->toBeFalse();
});

it('rifiuta la chiave principale di prova fuori da sviluppo e test', function (): void {
    config(['app.env' => 'production', 'saas.crypto.vault' => 'local', 'saas.crypto.master_key' => LocalKeyVault::TEST_MASTER_KEY]);
    app()->forgetInstance(KeyVault::class);

    expect(fn () => app(KeyVault::class))->toThrow(RuntimeException::class, 'chiave di prova');
});

it('accetta la chiave principale di prova in sviluppo e nei test', function (string $env): void {
    config(['app.env' => $env, 'saas.crypto.vault' => 'local', 'saas.crypto.master_key' => LocalKeyVault::TEST_MASTER_KEY]);
    app()->forgetInstance(KeyVault::class);

    expect(app(KeyVault::class))->toBeInstanceOf(LocalKeyVault::class);
})->with(['local', 'testing']);

it('accetta una chiave principale propria in produzione', function (): void {
    config(['app.env' => 'production', 'saas.crypto.vault' => 'local', 'saas.crypto.master_key' => base64_encode(random_bytes(32))]);
    app()->forgetInstance(KeyVault::class);

    expect(app(KeyVault::class))->toBeInstanceOf(LocalKeyVault::class);
});

it('la cache «failover» passa al database se Redis non è raggiungibile', function (): void {
    config([
        'cache.default' => 'failover',
        'cache.stores.failover.stores' => ['redis', 'database'],
        'database.redis.cache.host' => '/non/esiste.sock',
        'database.redis.cache.port' => 0,
        'database.redis.default.host' => '/non/esiste.sock',
        'database.redis.default.port' => 0,
    ]);

    Cache::forgetDriver('failover');
    Cache::put('prova-failover', 'ok', 60);

    expect(Cache::get('prova-failover'))->toBe('ok')
        ->and(Cache::store('database')->get('prova-failover'))->toBe('ok');
});

it('con Redis disponibile la piattaforma usa la cache «failover» e porta l\'indirizzo del pannello su entrambe le connessioni', function (): void {
    app(PlatformSettings::class)->save(['cache.redis_host' => '10.0.0.5', 'cache.redis_port' => '6380', 'cache.redis_password' => 'segreto'], [], null);
    config(['cache.default' => 'redis', 'database.redis.client' => 'predis']);

    app(PlatformConfig::class)->apply();

    expect(config('cache.default'))->toBe('failover');
    foreach (['default', 'cache'] as $connection) {
        expect(config("database.redis.{$connection}.host"))->toBe('10.0.0.5')
            ->and(config("database.redis.{$connection}.port"))->toBe(6380)
            ->and(config("database.redis.{$connection}.password"))->toBe('segreto');
    }
});

it('avvisa se Redis usa più del 70% della memoria, senza fermare il rilascio', function (): void {
    config(['cache.default' => 'failover', 'saas.health_strict' => false]);
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->with('info', ['memory'])->andReturn(['used_memory' => '50000000', 'maxmemory' => '67108864']);
    Redis::shouldReceive('connection')->with('cache')->andReturn($connection);

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('AVVISO memoria di Redis: Redis usa il 75% della memoria')
        ->assertSuccessful();
});

it('sotto il 70% della memoria Redis il controllo è verde', function (): void {
    config(['cache.default' => 'failover']);
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->with('info', ['memory'])->andReturn(['used_memory' => '10000000', 'maxmemory' => '67108864']);
    Redis::shouldReceive('connection')->with('cache')->andReturn($connection);

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('ok    memoria di Redis')
        ->assertSuccessful();
});

/** Un lavoro di posta in coda da $seconds secondi (rilasciato di recente: created_at è nuovo, conta quello del payload). */
function healthQueuedMail(string $queue, int $seconds): void
{
    DB::table('core.jobs')->insert([
        'queue' => $queue,
        'payload' => (string) json_encode(['uuid' => (string) Str::uuid(), 'createdAt' => now()->getTimestamp() - $seconds]),
        'attempts' => 1,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ]);
}

it('con le code della posta in ordine i controlli della posta sono verdi', function (): void {
    config(['queue.default' => 'database']);

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('ok    coda della posta urgente')
        ->expectsOutputToContain('ok    coda della posta massiva')
        ->expectsOutputToContain('ok    invio della posta')
        ->expectsOutputToContain('ok    posta non partita')
        ->assertSuccessful();
});

it('segnala un messaggio urgente in coda da più di 5 minuti', function (): void {
    config(['queue.default' => 'database']);
    healthQueuedMail(MailQueue::URGENT, 4 * 60);
    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ok    coda della posta urgente')->assertSuccessful();

    healthQueuedMail(MailQueue::URGENT, 6 * 60);
    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ERRORE coda della posta urgente')->assertFailed();
});

it('segnala un messaggio massivo in coda da più di 6 ore', function (): void {
    config(['queue.default' => 'database']);
    healthQueuedMail(MailQueue::BULK, 5 * 3600);
    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ok    coda della posta massiva')->assertSuccessful();

    healthQueuedMail(MailQueue::BULK, 7 * 3600);
    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ERRORE coda della posta massiva')->assertFailed();
});

it('segnala la posta ferma dopo un rifiuto del server; al rilascio è solo un avviso', function (): void {
    MailQueue::block(MailQueue::mailer());

    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ERRORE invio della posta')->assertFailed();
    $this->artisan('saas:health', ['--skip-migrations' => true, '--mail-warnings-only' => true])
        ->expectsOutputToContain('AVVISO invio della posta')
        ->assertSuccessful();
});

it('segnala i messaggi non partiti nelle ultime 24 ore, non quelli più vecchi né gli altri lavori', function (): void {
    $failed = static fn (string $queue, int $hours) => DB::table('core.failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => $queue, 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHours($hours),
    ]);
    $failed(MailQueue::BULK, 30);
    $failed('default', 1);
    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ok    posta non partita')->assertSuccessful();

    $failed(MailQueue::URGENT, 2);
    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ERRORE posta non partita: 1 ')->assertFailed();
});

it('avvisa quando gli invii dell\'ora superano il 90% del tetto totale', function (): void {
    $key = MailQueue::limits(MailQueue::URGENT)[0]->key;
    RateLimiter::increment($key, 3600, 126);
    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ok    invii della posta nell\'ora')->assertSuccessful();

    RateLimiter::increment($key, 3600, 1);
    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('AVVISO invii della posta nell\'ora: 127 su 140')
        ->assertSuccessful();
});
