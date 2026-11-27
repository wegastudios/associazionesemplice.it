<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Mail\LoginLink;
use App\Modules\AreaSoci\Mail\TwoFactorOffNotice;
use App\Modules\AreaSoci\Mail\TwoFactorResetLink;
use App\Modules\AreaSoci\Services\MemberAccessError;
use App\Modules\AreaSoci\Services\MemberTwoFactor;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Contracts\OrgTwoFactor;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

// Verifica in due passaggi dei soci (decisioni del titolare del 26-27/11/2026), allineata al plugin. Dati inventati.

beforeEach(function (): void {
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => []], null);
        }
    });
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->base = 'http://'.$this->org->slug.'.associazionesemplice.it/area';
    $this->mario = $this->orgContext()->run($this->org->id, function (): Person {
        $s = app(OrgSettings::class);
        $s->set('ente.email', 'segreteria@alfa.example');
        $s->set('ente.phone', '06 1234567');
        $mario = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'card_number' => '7', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($mario->id, Account::query()->sole()->id, 3000);

        return $mario;
    });
});

/** Accende la verifica della scheda dal servizio: restituisce il segreto e i codici di riserva. @return array{0: string, 1: list<string>} */
function tfaOn(object $test): array
{
    return app(OrgContext::class)->run($test->org->id, function () use ($test): array {
        $tfa = app(MemberTwoFactor::class);
        $secret = $tfa->start($test->mario->id, 'Polisportiva Alfa', 'mario@example.com')['secret'];
        $codes = $tfa->confirm($test->mario->id, (new Google2FA)->getCurrentOtp($secret));
        // Il codice usato per accenderla non vale più: per le prove si riapre il passo.
        DB::table('app.member_two_factor')->update(['last_step' => 0]);

        return [$secret, $codes];
    });
}

/** Primo passaggio (email e codice): restituisce la risposta del codice email. */
function tfaFirstStep(object $test): object
{
    Mail::fake();
    $test->post($test->base.'/accesso', ['email' => 'mario@example.com']);
    $mail = Mail::sent(LoginLink::class)->last();

    return $test->post($test->base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => $mail instanceof LoginLink ? $mail->code : '']);
}

function tfaIsOn(object $test): bool
{
    return app(OrgContext::class)->run($test->org->id, fn (): bool => app(MemberTwoFactor::class)->isOn($test->mario->id));
}

it('accesa dal socio: il codice giusto la conferma e consegna 10 codici di riserva di 10 cifre', function (): void {
    [, $codes] = tfaOn($this);

    expect($codes)->toHaveCount(10)
        ->and(collect($codes)->every(fn (string $c): bool => preg_match('/^\d{10}$/', $c) === 1))->toBeTrue()
        ->and(MemberTwoFactor::showBackup($codes[0]))->toMatch('/^\d{5}-\d{5}$/')
        ->and(tfaIsOn($this))->toBeTrue();
});

it('con la verifica accesa, dopo il codice via email serve quello dell\'app; un codice sbagliato non fa entrare', function (): void {
    [$secret] = tfaOn($this);

    tfaFirstStep($this)->assertRedirect('/area/accesso/verifica')->assertCookieMissing('as_socio');
    $this->get($this->base.'/accesso/verifica')->assertInertia(fn (Assert $page) => $page->component('AreaSoci/CodiceVerifica'));

    $this->post($this->base.'/accesso/verifica', ['code' => '000000'])
        ->assertSessionHasErrors(['code' => MemberTwoFactor::WRONG_MSG]);

    $this->post($this->base.'/accesso/verifica', ['code' => (new Google2FA)->getCurrentOtp($secret)])
        ->assertRedirect('/area')->assertCookie('as_socio');
});

it('un codice dell\'app già usato non vale una seconda volta', function (): void {
    [$secret] = tfaOn($this);
    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->orgContext()->run($this->org->id, function () use ($code): void {
        app(MemberTwoFactor::class)->check($this->mario->id, $code);
        expect(fn () => app(MemberTwoFactor::class)->check($this->mario->id, $code))->toThrow(MemberAccessError::class, MemberTwoFactor::WRONG_MSG);
    });
});

it('un codice di riserva fa entrare una volta sola', function (): void {
    [, $codes] = tfaOn($this);

    tfaFirstStep($this)->assertRedirect('/area/accesso/verifica');
    $this->post($this->base.'/accesso/verifica', ['code' => MemberTwoFactor::showBackup($codes[3])])->assertRedirect('/area');

    $this->orgContext()->run($this->org->id, function () use ($codes): void {
        expect(app(MemberTwoFactor::class)->status($this->mario->id)['backups'])->toBe(9);
        expect(fn () => app(MemberTwoFactor::class)->check($this->mario->id, $codes[3]))->toThrow(MemberAccessError::class);
    });
});

it('5 codici sbagliati bloccano per 10 minuti, anche il codice giusto', function (): void {
    [$secret] = tfaOn($this);

    $this->orgContext()->run($this->org->id, function () use ($secret): void {
        $tfa = app(MemberTwoFactor::class);
        for ($i = 0; $i < 4; $i++) {
            expect(fn () => $tfa->check($this->mario->id, '111111'))->toThrow(MemberAccessError::class, MemberTwoFactor::WRONG_MSG);
        }
        expect(fn () => $tfa->check($this->mario->id, '111111'))->toThrow(MemberAccessError::class, MemberTwoFactor::BLOCKED_MSG)
            ->and(fn () => $tfa->check($this->mario->id, (new Google2FA)->getCurrentOtp($secret)))->toThrow(MemberAccessError::class, MemberTwoFactor::BLOCKED_MSG);
    });
});

it('se l\'associazione la chiede ai soci, chi non l\'ha vede solo il profilo per accenderla e non può spegnerla', function (): void {
    $this->orgContext()->run($this->org->id, fn () => app(OrgTwoFactor::class)->setMembersRequiredHere(true));
    $cookie = (string) tfaFirstStep($this)->assertRedirect('/area')->getCookie('as_socio', false)?->getValue();

    $this->withUnencryptedCookie('as_socio', $cookie)->get($this->base.'/quote')->assertRedirect('/area/profilo#verifica');
    $this->withUnencryptedCookie('as_socio', $cookie)->get($this->base.'/profilo')
        ->assertInertia(fn (Assert $page) => $page->where('twoFactor.required', true)->where('twoFactor.on', false));

    [$secret] = tfaOn($this);
    $this->withUnencryptedCookie('as_socio', $cookie)->get($this->base.'/quote')->assertOk();
    $this->withUnencryptedCookie('as_socio', $cookie)->post($this->base.'/profilo/verifica/spegni', ['code' => (new Google2FA)->getCurrentOtp($secret)])
        ->assertSessionHasErrors('tfa');
    expect(tfaIsOn($this))->toBeTrue();
});

it('senza obbligo il socio la spegne con un codice (qui di riserva: l\'orologio delle prove non cambia il codice dell\'app)', function (): void {
    [$secret, $codes] = tfaOn($this);
    tfaFirstStep($this);
    $cookie = (string) $this->post($this->base.'/accesso/verifica', ['code' => (new Google2FA)->getCurrentOtp($secret)])->getCookie('as_socio', false)?->getValue();

    // Lo stesso codice dell'app appena usato per entrare non spegne nulla.
    $this->withUnencryptedCookie('as_socio', $cookie)->post($this->base.'/profilo/verifica/spegni', ['code' => (new Google2FA)->getCurrentOtp($secret)])
        ->assertSessionHasErrors('tfa');
    expect(tfaIsOn($this))->toBeTrue();

    $this->withUnencryptedCookie('as_socio', $cookie)->post($this->base.'/profilo/verifica/spegni', ['code' => $codes[0]])
        ->assertRedirect('/area/profilo#verifica')->assertSessionHasNoErrors();
    expect(tfaIsOn($this))->toBeFalse();
});

it('telefono e codici persi: il link via email (una volta sola) la spegne, chiude gli accessi e avvisa socio e segreteria', function (): void {
    tfaOn($this);
    tfaFirstStep($this)->assertRedirect('/area/accesso/verifica');

    $this->post($this->base.'/accesso/verifica/ripristino')->assertRedirect('/area/accesso/verifica');
    $mail = Mail::sent(TwoFactorResetLink::class)->last();
    expect($mail)->toBeInstanceOf(TwoFactorResetLink::class);
    $path = (string) parse_url($mail->link, PHP_URL_PATH);

    $this->get('http://'.$this->org->slug.'.associazionesemplice.it'.$path)->assertInertia(fn (Assert $page) => $page->component('AreaSoci/RipristinoVerifica'));
    // Aprire la pagina non lo consuma (i controlli della posta lo aprono): la verifica è ancora accesa.
    expect(tfaIsOn($this))->toBeTrue();

    $this->post('http://'.$this->org->slug.'.associazionesemplice.it'.$path)->assertRedirect('/area')->assertCookie('as_socio');
    expect(tfaIsOn($this))->toBeFalse();
    Mail::assertSent(TwoFactorOffNotice::class, fn (TwoFactorOffNotice $m): bool => $m->hasTo('mario@example.com') && ! $m->toStaff && $m->how === 'email');
    Mail::assertSent(TwoFactorOffNotice::class, fn (TwoFactorOffNotice $m): bool => $m->hasTo('segreteria@alfa.example') && $m->toStaff && $m->personName === 'Mario Rossi');

    $this->post('http://'.$this->org->slug.'.associazionesemplice.it'.$path)->assertRedirect('/area/accesso');
});

it('la segreteria la spegne dalla scheda: accessi chiusi e avviso al socio', function (): void {
    tfaOn($this);
    Mail::fake();
    $user = $this->createUser('segreteria@example.com', twoFactor: true);
    $this->addOperator($this->org, $user, OrgRole::Operator);

    $this->actingAs($user)->postJson("/gestionale/soci/{$this->mario->id}/area-soci/verifica-spegni")->assertOk()->assertJson(['done' => true]);

    expect(tfaIsOn($this))->toBeFalse();
    Mail::assertSent(TwoFactorOffNotice::class, fn (TwoFactorOffNotice $m): bool => $m->hasTo('mario@example.com') && $m->how === 'staff');
    $this->actingAs($user)->postJson("/gestionale/soci/{$this->mario->id}/area-soci/verifica-spegni")->assertJson(['done' => false]);
});

it('la casella per i soci la cambiano titolare e amministratore, ed è nel registro', function (): void {
    $owner = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner);

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->put('/gestionale/operatori/verifica-soci', ['required' => true])->assertSessionHasNoErrors();

    $this->orgContext()->run($this->org->id, function (): void {
        expect(app(OrgTwoFactor::class)->membersRequiredHere())->toBeTrue()
            ->and(DB::table('audit.log')->where('action', 'org.two_factor.members_required')->exists())->toBeTrue();
    });
});
