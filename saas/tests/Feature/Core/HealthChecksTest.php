<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Cifratura\Keys\LocalKeyVault;
use App\Modules\Core\Platform\PlatformConfig;

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
