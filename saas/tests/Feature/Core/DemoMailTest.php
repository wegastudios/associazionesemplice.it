<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Auth\InvitationNotification;
use App\Modules\Core\Auth\ResetPasswordNotification;
use App\Modules\Core\Demo\DemoMailGuard;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Mail;

/*
 * Nella demo nessuna posta esce davvero: i mailer sono tutti «log», anche se per errore il .env o le impostazioni della
 * piattaforma dicono smtp o Emailit. Ambiente «testing» (non «demo») perché le prove devono saltare il controllo CSRF.
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

it('con la demo accesa ogni mailer diventa log, anche con smtp, Emailit e posta principale impostati', function (): void {
    wrongMailSetup();
    DemoMailGuard::enforce();
    Mail::purge();

    expect(config('mail.default'))->toBe('log')->and(config('saas.mail_queue.primary'))->toBe('cpanel');
    $mailers = array_keys((array) config('mail.mailers'));
    expect($mailers)->toContain('smtp', 'emailit', 'failover');
    foreach ($mailers as $name) {
        if (in_array($name, ['log', 'array'], true)) {
            continue;
        }
        expect(Mail::mailer((string) $name)->getSymfonyTransport())->toBeInstanceOf(LogTransport::class, (string) $name);
    }
    // Nemmeno il ripiego «failover» o i servizi esterni (ses, postmark, resend) possono mandare fuori qualcosa.
    expect(config('mail.mailers.failover.transport'))->toBe('log')->and(config('mail.mailers.ses.transport'))->toBe('log');
});

it('con la demo accesa le email semplici, gli inviti e il reimpostare la password non danno errore e non escono', function (): void {
    wrongMailSetup();
    DemoMailGuard::enforce();
    Mail::purge();
    $user = $this->createUser('anna@example.com', twoFactor: true);

    // Con smtp vero e irraggiungibile questi invii darebbero errore: così passano dal registro.
    Mail::raw('prova', fn ($message) => $message->to('qualcuno@example.test')->subject('Prova'));
    $user->notify(new InvitationNotification('Circolo Alfa', 'token-di-prova'));
    $user->notify(new ResetPasswordNotification('token-di-prova'));

    expect(Mail::mailer((string) config('mail.default'))->getSymfonyTransport())->toBeInstanceOf(LogTransport::class);
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

it('il link e il codice dell\'area soci in demo vanno nel registro: nessun errore anche con la posta vera sbagliata', function (): void {
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => []], null);
        }
    });
    $org = $this->createOrg('Polisportiva Beta');
    $this->orgContext()->run($org->id, function (): void {
        $mario = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'card_number' => '7', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($mario->id, Account::query()->sole()->id, 3000);
    });
    wrongMailSetup();
    DemoMailGuard::enforce();
    Mail::purge();

    $this->post('http://'.$org->slug.'.associazionesemplice.it/area/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso');

    expect(Mail::mailer((string) config('mail.default'))->getSymfonyTransport())->toBeInstanceOf(LogTransport::class);
});
