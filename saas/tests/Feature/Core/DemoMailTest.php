<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Auth\InvitationNotification;
use App\Modules\Core\Auth\ResetPasswordNotification;
use App\Modules\Core\Demo\DemoMailGuard;
use App\Modules\Core\Demo\DemoResetGuard;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
 * Nella demo nessuna posta esce e nessuna resta scritta: i mailer sono tutti «array» (il messaggio vive in memoria per la
 * richiesta e sparisce), anche se per errore il .env o le impostazioni della piattaforma dicono smtp o Emailit, e nel file
 * di log non restano indirizzi, codici né link. Ambiente «testing» (non «demo») perché le prove devono saltare il CSRF.
 */

/** Come se per errore la posta vera fosse accesa: smtp irraggiungibile, Emailit con una chiave, posta principale emailit. */
function wrongMailSetup(): void
{
    config([
        'saas.demo.enabled' => true,
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '203.0.113.9', 'mail.mailers.smtp.port' => 2525,
        'mail.mailers.emailit.password' => 'chiave-finta-di-prova',
        'saas.mail_queue.primary' => 'emailit',
    ]);
}

/** Un file di log di prova al posto di quello dell'applicazione: dopo ogni prova si legge cosa c'è scritto. */
function demoTestLog(): string
{
    $id = bin2hex(random_bytes(4));
    $path = sys_get_temp_dir().'/demo-log-'.$id.'.log';
    // Canale con nome nuovo a ogni prova: non c'è un'istanza vecchia da dimenticare.
    config(['logging.default' => 'demo_'.$id, 'logging.channels.demo_'.$id => ['driver' => 'single', 'path' => $path, 'level' => 'debug']]);
    Log::channel('demo_'.$id)->debug('prova');
    file_put_contents($path, '');

    return $path;
}

it('con la demo accesa ogni mailer diventa array, anche con smtp, Emailit e posta principale impostati', function (): void {
    wrongMailSetup();
    DemoMailGuard::enforce();
    Mail::purge();

    expect(config('mail.default'))->toBe('array')->and(config('saas.mail_queue.primary'))->toBe('cpanel');
    $mailers = array_keys((array) config('mail.mailers'));
    expect($mailers)->toContain('smtp', 'emailit', 'failover', 'log');
    foreach ($mailers as $name) {
        expect(Mail::mailer((string) $name)->getSymfonyTransport())->toBeInstanceOf(ArrayTransport::class, (string) $name);
    }
});

it('con la demo accesa email, inviti e reimpostazione password non danno errore, non escono e non lasciano niente nel log', function (): void {
    wrongMailSetup();
    DemoMailGuard::enforce();
    Mail::purge();
    $log = demoTestLog();
    $user = $this->createUser('anna@example.com', twoFactor: true);

    // Con smtp vero e irraggiungibile questi invii darebbero errore; con «log» lascerebbero indirizzo e link nel file.
    Mail::raw('Il tuo codice è 482913. Entra da https://demosaas.associazionesemplice.it/area/entra/abc', fn ($message) => $message->to('visitatore.vero@example.test')->subject('Codice'));
    $user->notify(new InvitationNotification('Circolo Alfa', 'token-di-prova-segreto'));
    $user->notify(new ResetPasswordNotification('token-di-reset-segreto'));

    $written = is_file($log) ? (string) file_get_contents($log) : '';
    @unlink($log);
    expect($written)->not->toContain('visitatore.vero@example.test')->not->toContain('anna@example.com')->not->toContain('482913')
        ->not->toContain('token-di-prova-segreto')->not->toContain('token-di-reset-segreto')->not->toContain('/area/entra/');
});

it('fuori dalla demo il guardiano non cambia nulla', function (): void {
    config(['saas.demo.enabled' => false, 'mail.default' => 'smtp', 'saas.mail_queue.primary' => 'emailit']);
    DemoMailGuard::enforce();

    expect(config('mail.default'))->toBe('smtp')->and(config('mail.mailers.smtp.transport'))->toBe('smtp')->and(config('saas.mail_queue.primary'))->toBe('emailit');

    // In produzione non si attiva nemmeno con SAAS_DEMO acceso per errore.
    config(['saas.demo.enabled' => true]);
    app()->detectEnvironment(fn (): string => 'production');
    DemoMailGuard::enforce();
    expect(config('mail.default'))->toBe('smtp');
});

it('il codice e il link dell\'area soci in demo non danno errore e non lasciano indirizzo, codice o link nel log', function (): void {
    $entitlements = Mockery::mock(Entitlements::class);
    $entitlements->shouldReceive('for')->andReturn(new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => []], null));
    app()->instance(Entitlements::class, $entitlements);
    $org = $this->createOrg('Polisportiva Beta');
    $this->orgContext()->run($org->id, function (): void {
        $mario = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'card_number' => '7', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($mario->id, Account::query()->sole()->id, 3000);
    });
    wrongMailSetup();
    DemoMailGuard::enforce();
    Mail::purge();
    $log = demoTestLog();

    $this->post('http://'.$org->slug.'.associazionesemplice.it/area/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso');

    $written = is_file($log) ? (string) file_get_contents($log) : '';
    @unlink($log);
    expect(Mail::mailer((string) config('mail.default'))->getSymfonyTransport())->toBeInstanceOf(ArrayTransport::class)
        ->and($written)->not->toContain('mario@example.com')->not->toContain('/entra/');
});

it('l\'azzeramento notturno toglie i log, solo dentro la cartella della demo', function (): void {
    $base = sys_get_temp_dir().'/demo-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($base.'/saas-demo/shared/storage/logs');
    File::ensureDirectoryExists($base.'/altrove/logs');
    File::put($base.'/saas-demo/shared/storage/logs/laravel-2026-11-24.log', 'visitatore.vero@example.test');
    File::put($base.'/saas-demo/shared/storage/logs/cron.log', 'x');
    File::put($base.'/saas-demo/shared/storage/logs/.gitignore', '*');
    File::put($base.'/altrove/logs/laravel.log', 'non toccare');

    try {
        expect(DemoResetGuard::clearLogs($base.'/saas-demo/shared/storage/logs', 'saas-demo'))->toBe(2)
            ->and(File::glob($base.'/saas-demo/shared/storage/logs/*.log'))->toBe([])
            ->and(file_exists($base.'/saas-demo/shared/storage/logs/.gitignore'))->toBeTrue()
            // fuori da una cartella saas-demo non si tocca niente
            ->and(DemoResetGuard::clearLogs($base.'/altrove/logs', 'saas-demo'))->toBeNull()
            ->and((string) file_get_contents($base.'/altrove/logs/laravel.log'))->toBe('non toccare');
    } finally {
        File::deleteDirectory($base);
    }
});
