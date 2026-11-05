<?php

declare(strict_types=1);

use App\Modules\Core\Platform\PlatformConfig;
use App\Modules\Core\Platform\PlatformSettings;
use Illuminate\Support\Facades\DB;

// Valori di prova inventati.
it('salva le impostazioni cifrate e non restituisce i segreti', function (): void {
    $s = app(PlatformSettings::class);
    $s->save(['mail.host' => 'mail.example.org', 'mail.port' => '587', 'mail.password' => 'segreto-di-prova'], [], null);

    $raw = (string) DB::table('core.platform_settings')->where('key', 'mail.password')->value('value_enc');
    expect($raw)->not->toContain('segreto-di-prova')
        ->and($s->all())->toMatchArray(['mail.host' => 'mail.example.org', 'mail.password' => 'segreto-di-prova'])
        ->and($s->forPage()['mail.password'])->toBe(['label' => 'Password', 'secret' => true, 'set' => true, 'value' => ''])
        ->and($s->forPage()['mail.host']['value'])->toBe('mail.example.org');

    // Un segreto vuoto resta com'è; si toglie solo chiedendolo. Un valore normale vuoto si toglie.
    $s->save(['mail.password' => '', 'mail.port' => ''], [], null);
    expect($s->all())->toHaveKey('mail.password')->not->toHaveKey('mail.port');
    $s->save(['mail.password' => ''], ['mail.password'], null);
    expect($s->all())->not->toHaveKey('mail.password');

    expect(fn () => $s->save(['mail.port' => '99999', 'keys.service_url' => 'http://insicuro.example'], [], null))
        ->toThrow(InvalidArgumentException::class, 'porta non valida')
        ->and(fn () => $s->save(['boh.boh' => 'x'], [], null))->toThrow(InvalidArgumentException::class);
});

it('porta le impostazioni nella configurazione e applica i ripieghi', function (): void {
    app(PlatformSettings::class)->save([
        'mail.host' => 'mail.example.org', 'mail.port' => '465', 'mail.encryption' => 'ssl', 'mail.from_address' => 'noreply@example.org',
        'backup.sftp_host' => 'backup.example.org', 'backup.sftp_username' => 'u1', 'backup.sftp_root' => '/dump',
    ], [], null);
    config(['mail.default' => 'log', 'cache.default' => 'redis', 'database.redis.default.host' => '/non/esiste.sock', 'saas.crypto.vault' => 'remote', 'saas.crypto.service_url' => null]);

    $warnings = app(PlatformConfig::class)->apply();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('mail.example.org')
        ->and(config('mail.mailers.smtp.port'))->toBe(465)
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.from.address'))->toBe('noreply@example.org')
        ->and(config('filesystems.disks.backups'))->toMatchArray(['driver' => 'sftp', 'host' => 'backup.example.org', 'username' => 'u1', 'root' => '/dump', 'port' => 22])
        ->and(config('cache.default'))->toBe('database')
        ->and(config('saas.crypto.vault'))->toBe('local')
        ->and(config('saas.crypto.trial'))->toBeTrue()
        ->and(implode(' ', $warnings))->toContain('Redis')->toContain('Servizio chiavi');
});

it('ripiega su posta nel log e backup sul server se mancano', function (): void {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'filesystems.disks.backups' => ['driver' => 'sftp', 'host' => null]]);
    $warnings = app(PlatformConfig::class)->apply();
    expect(config('mail.default'))->toBe('log')->and(config('filesystems.disks.backups.driver'))->toBe('local')
        ->and(implode(' ', $warnings))->toContain('Posta')->toContain('backup');
});

it('non crea associazioni dal portale finché la piattaforma è di prova', function (): void {
    config(['saas.crypto.trial' => true]);
    $this->postJson('/api/portale/v1/orgs', ['token' => 'x'])->assertStatus(503)->assertJson(['error' => 'key_service_not_configured']);
});
