<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Http\Middleware\AuthenticateMember;
use App\Modules\AreaSoci\Mail\LoginLink;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\GuardianService;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.

function memberArea(bool $on): void
{
    app()->instance(Entitlements::class, new class($on) implements Entitlements
    {
        public function __construct(private bool $on) {}

        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, $this->on ? ['member_area' => []] : [], null);
        }
    });
}

beforeEach(function (): void {
    memberArea(true);
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->base = 'http://'.$this->org->slug.'.associazionesemplice.it/area';
    [$this->mario, $this->luca] = $this->orgContext()->run($this->org->id, function (): array {
        $s = app(OrgSettings::class);
        $s->set('ente.email', 'segreteria@alfa.example');
        $s->set('ente.phone', '06 1234567');
        $mario = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'card_number' => '7', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($mario->id, Account::query()->sole()->id, 3000);
        $luca = Person::create(['first_name' => 'Luca', 'last_name' => 'Rossi', 'birth_date' => now()->subYears(10)->toDateString()]);
        app(GuardianService::class)->link($luca->id, $mario->id);

        return [$mario, $luca];
    });
});

/** Entra con email e codice e restituisce il cookie della sessione. */
function memberLogin(object $test, string $email = 'mario@example.com'): string
{
    Mail::fake();
    $test->post($test->base.'/accesso', ['email' => $email])->assertRedirect('/area/accesso');
    $code = '';
    Mail::assertSent(LoginLink::class, function (LoginLink $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });
    $r = $test->post($test->base.'/accesso/codice', ['email' => $email, 'code' => $code])->assertRedirect('/area');

    return (string) $r->getCookie('as_socio', false)?->getValue();
}

function asMember(object $test, string $cookie): object
{
    return $test->withUnencryptedCookie('as_socio', $cookie);
}

/** Il codice dell'ultima email di accesso inviata. */
function lastLoginCode(): string
{
    $mail = Mail::sent(LoginLink::class)->last();

    return $mail instanceof LoginLink ? $mail->code : '';
}

function wrongCode(string $code): string
{
    return $code === '111111' ? '222222' : '111111';
}

it('blocca il codice dopo cinque errori, anche se poi è quello giusto', function (): void {
    Mail::fake();
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com']);
    $code = lastLoginCode();

    foreach (range(1, 5) as $_) {
        $this->post($this->base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => wrongCode($code)])->assertSessionHasErrors('code');
    }
    $this->post($this->base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => $code])->assertSessionHasErrors('code');
    $this->orgContext()->run($this->org->id, fn () => expect((int) DB::table('app.member_login_tokens')->value('attempts'))->toBe(5));
});

it('non azzera il limite dei codici sbagliati chiedendo un codice nuovo', function (): void {
    Mail::fake();
    foreach (range(1, 2) as $_) {
        $this->post($this->base.'/accesso', ['email' => 'mario@example.com']);
        foreach (range(1, 5) as $__) {
            $this->post($this->base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => wrongCode(lastLoginCode())])->assertSessionHasErrors('code');
        }
    }
    // Dieci errori sulla stessa email: nemmeno il codice nuovo e giusto entra, finché non passa la finestra.
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com']);
    $this->post($this->base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => lastLoginCode()])->assertSessionHasErrors('code');
});

it('non fa esaurire a un estraneo le richieste di codice di un socio', function (): void {
    Mail::fake();
    foreach (range(1, 6) as $_) {
        $this->post($this->base.'/accesso', ['email' => 'mario@example.com']);
    }
    Mail::assertSent(LoginLink::class, 5);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post($this->base.'/accesso', ['email' => 'mario@example.com']);
    Mail::assertSent(LoginLink::class, 6);
});

it('non fa entrare chi è uscito dall\'associazione o è sospeso, con lo stesso messaggio di chi non è socio', function (): void {
    $cookie = memberLogin($this);
    asMember($this, $cookie)->get($this->base)->assertOk();

    $this->orgContext()->run($this->org->id, fn () => Person::query()->whereKey($this->mario->id)->update(['suspended_at' => now()]));
    // La sessione già aperta viene respinta alla richiesta dopo.
    asMember($this, $cookie)->get($this->base)->assertRedirect('/area/accesso');

    Mail::fake();
    $unknown = $this->post($this->base.'/accesso', ['email' => 'nessuno@example.com'])->assertRedirect('/area/accesso')->getSession()->get('member_login_message');
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso')->assertSessionHas('member_login_message', $unknown);
    Mail::assertNothingSent();

    $this->orgContext()->run($this->org->id, function (): void {
        Person::query()->whereKey($this->mario->id)->update(['suspended_at' => null]);
        Person::query()->whereKey($this->mario->id)->update(['left_on' => now()->toDateString()]);
    });
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso');
    Mail::assertNothingSent();
});

it('fa entrare l\'ex socio tutore operativo di un minorenne socio, solo per la scheda del figlio', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        Person::query()->whereKey($this->luca->id)->update(['type' => 'ordinary']);
        Person::query()->whereKey($this->mario->id)->update(['left_on' => now()->toDateString()]);
    });

    $cookie = memberLogin($this);
    asMember($this, $cookie)->get($this->base)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('member.profiles', 1)->where('member.profiles.0.id', $this->luca->id)->where('member.profiles.0.own', false)
            ->where('member.active', $this->luca->id)->where('card.id', $this->luca->id));
    // Il proprio profilo non si apre: resta la scheda del figlio.
    asMember($this, $cookie)->get($this->base.'/profilo')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('card.id', $this->luca->id));
    asMember($this, $cookie)->withSession([AuthenticateMember::ACTIVE => $this->mario->id])->get($this->base.'/profilo')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('card.id', $this->luca->id));

    // Se il figlio lascia l'associazione, l'ex socio non entra più (la sessione aperta viene respinta).
    $this->orgContext()->run($this->org->id, fn () => Person::query()->whereKey($this->luca->id)->update(['left_on' => now()->toDateString()]));
    asMember($this, $cookie)->get($this->base)->assertRedirect('/area/accesso');
});

it('non fa entrare il tutore sospeso, nemmeno se gestisce un figlio socio', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        Person::query()->whereKey($this->luca->id)->update(['type' => 'ordinary']);
        Person::query()->whereKey($this->mario->id)->update(['left_on' => now()->toDateString(), 'suspended_at' => now()]);
    });
    Mail::fake();
    $unknown = $this->post($this->base.'/accesso', ['email' => 'nessuno@example.com'])->assertRedirect('/area/accesso')->getSession()->get('member_login_message');
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso')->assertSessionHas('member_login_message', $unknown);
    Mail::assertNothingSent();

    // Anche da socio (non uscito) ma sospeso.
    $this->orgContext()->run($this->org->id, fn () => Person::query()->whereKey($this->mario->id)->update(['left_on' => null]));
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso');
    Mail::assertNothingSent();
});

it('non fa entrare l\'ex socio che non è tutore operativo di un minorenne socio', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        Person::query()->whereKey($this->luca->id)->update(['type' => 'ordinary']);
        Person::query()->whereKey($this->mario->id)->update(['left_on' => now()->toDateString()]);
        Person::create(['first_name' => 'Anna', 'last_name' => 'Bianchi', 'email' => 'anna@example.com', 'birth_date' => '1985-05-05', 'left_on' => now()->toDateString()]);
    });
    Mail::fake();
    $this->post($this->base.'/accesso', ['email' => 'anna@example.com'])->assertRedirect('/area/accesso');
    Mail::assertNothingSent();

    // Il figlio è maggiorenne, oppure non è più socio: il tutore ex socio resta fuori.
    $this->orgContext()->run($this->org->id, fn () => Person::query()->whereKey($this->luca->id)->update(['birth_date' => now()->subYears(20)->toDateString()]));
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso');
    Mail::assertNothingSent();
});

it('fa entrare il socio con il codice per email, e solo una volta', function (): void {
    Mail::fake();
    // Email sconosciuta: stessa risposta, nessuna email.
    $this->post($this->base.'/accesso', ['email' => 'nessuno@example.com'])->assertRedirect('/area/accesso')
        ->assertSessionHas('member_login_message');
    Mail::assertNothingSent();

    $this->post($this->base.'/accesso', ['email' => 'MARIO@example.com']);
    $mail = null;
    Mail::assertSent(LoginLink::class, function (LoginLink $m) use (&$mail): bool {
        $mail = $m;

        return $m->hasTo('mario@example.com');
    });
    expect($mail->link)->toStartWith($this->base.'/entra/');

    $this->post($this->base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => $mail->code === '111111' ? '222222' : '111111'])
        ->assertSessionHasErrors('code');
    $cookie = $this->post($this->base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => $mail->code])->assertRedirect('/area')->getCookie('as_socio', false);
    expect($cookie)->not->toBeNull();
    $this->post($this->base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => $mail->code])->assertSessionHasErrors('code');

    // Usato il codice, il link della stessa email non vale più; un link nuovo vale una volta sola.
    $this->post($this->base.'/entra/'.substr($mail->link, strrpos($mail->link, '/') + 1))->assertRedirect('/area/accesso');
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com']);
    $links = [];
    Mail::assertSent(LoginLink::class, function (LoginLink $m) use (&$links): bool {
        $links[] = $m->link;

        return true;
    });
    $token = substr((string) end($links), strrpos((string) end($links), '/') + 1);
    // Aprire il link (come fanno i controlli della posta) non lo consuma: la pagina ha il pulsante, il POST fa entrare.
    $this->get($this->base.'/entra/'.$token)->assertOk()->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Entra')->where('token', $token));
    $this->get($this->base.'/entra/'.$token)->assertOk();
    $this->post($this->base.'/entra/'.$token)->assertRedirect('/area');
    $this->post($this->base.'/entra/'.$token)->assertRedirect('/area/accesso');

    asMember($this, (string) $cookie->getValue())->get($this->base)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Tessera')->where('card.card_number', '7')->where('card.active', true)
            ->where('qr', fn ($qr) => str_starts_with((string) $qr, '<svg'))->has('member.profiles', 2));
    $this->orgContext()->run($this->org->id, fn () => expect(DB::table('audit.log')->where('action', 'member.login')->value('actor_type'))->toBe('system')
        ->and(DB::table('app.member_sessions')->whereNotNull('token_hash')->count())->toBe(2));
});

it('senza pacchetto o senza sessione l\'area soci non si apre', function (): void {
    $this->get($this->base)->assertRedirect('/area/accesso');
    $this->withUnencryptedCookie('as_socio', str_repeat('a', 43))->get($this->base)->assertRedirect('/area/accesso');
    $this->get('http://non-esiste-qui.associazionesemplice.it/area/accesso')->assertNotFound();
    memberArea(false);
    $this->get($this->base.'/accesso')->assertNotFound();
});

it('mostra profilo, quote, ricevute ed eventi, e la scheda dei figli', function (): void {
    $cookie = memberLogin($this);
    $me = asMember($this, $cookie);
    $me->get($this->base.'/profilo')->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Profilo')
        ->where('details.tax_code', 'RSSMRA80A01H501U')->has('details.memberships', 1));
    $me->put($this->base.'/profilo', ['phone' => '333 1234567', 'address' => 'Via Roma 1, Milano'])->assertSessionHas('status', 'Recapiti aggiornati.');
    $this->orgContext()->run($this->org->id, function (): void {
        expect(Person::query()->find($this->mario->id)?->phone)->not->toBeNull()
            ->and(DB::table('audit.log')->where('actor_type', 'member')->where('actor_id', $this->mario->id)->exists())->toBeTrue();
    });

    $receipt = '';
    $me->get($this->base.'/quote')->assertInertia(function (Assert $page) use (&$receipt) {
        $page->component('AreaSoci/Quote')->has('receipts', 1)->where('receipts.0.total', 3000);
        $receipt = $page->toArray()['props']['receipts'][0]['id'];
    });
    $me->get($this->base.'/ricevute/'.$receipt.'.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');

    // Un'altra persona con un'altra ricevuta: non si vede.
    $other = $this->orgContext()->run($this->org->id, function (): string {
        $p = Person::create(['first_name' => 'Eva', 'last_name' => 'Verdi']);

        return (string) (app(MembershipService::class)->renewWithPayment($p->id, Account::query()->sole()->id, 3000)['receipt_id'] ?? '');
    });
    $me->get($this->base.'/ricevute/'.$other.'.pdf')->assertNotFound();

    // Eventi: prenota, l'altro posto finisce, lista d'attesa, annulla.
    [$s1, $s2] = $this->orgContext()->run($this->org->id, function (): array {
        $a = app(ActivityService::class)->save(null, ['name' => 'Gite', 'kind' => 'recurring', 'social_year' => app(MembershipService::class)->socialYearLabel(), 'fee_cents' => 1000]);
        $s = app(SessionService::class);
        $s->addDates($a->id, now()->addDays(3)->toDateString(), null, false, '09:00', null, null, 1);
        $s->addDates($a->id, now()->addDays(4)->toDateString(), null, false, '09:00', null, null, 1);
        $ids = array_column($s->sessions($a), 'id');
        $s->book($ids[1], Person::create(['first_name' => 'Zoe', 'last_name' => 'Neri'])->id);

        return $ids;
    });
    $me->post($this->base."/eventi/{$s1}/prenota")->assertSessionHas('status');
    $me->post($this->base."/eventi/{$s2}/prenota")->assertSessionHasErrors('ledger');
    $me->post($this->base."/eventi/{$s2}/attesa")->assertSessionHas('status');
    $me->get($this->base.'/eventi')->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Eventi')
        ->where('events.0.booking_id', fn ($v) => is_string($v))->where('events.1.waiting', true));
    $booking = (string) $this->orgContext()->run($this->org->id, fn () => DB::table('app.session_bookings')->where('person_id', $this->mario->id)->value('id'));
    $me->post($this->base."/prenotazioni/{$booking}/annulla")->assertSessionHas('status', 'Prenotazione annullata.');

    // La scheda del figlio: si apre, quella di un estraneo no.
    $me->post($this->base.'/scheda/'.$this->luca->id)->assertRedirect('/area');
    $me->withSession([App\Modules\AreaSoci\Http\Middleware\AuthenticateMember::ACTIVE => $this->luca->id])->get($this->base.'/profilo')
        ->assertInertia(fn (Assert $page) => $page->where('card.first_name', 'Luca'));
    $me->post($this->base.'/scheda/'.$this->org->id)->assertNotFound();

    $me->post($this->base.'/esci')->assertRedirect('/area/accesso');
    asMember($this, $cookie)->get($this->base)->assertRedirect('/area/accesso');
});

it('verifica la tessera dal QR solo con la firma', function (): void {
    $url = URL::signedRoute('member.verify', ['org' => $this->org->slug, 'person' => $this->mario->id]);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Verifica')->where('card.name', 'Mario R.')->where('card.active', true));
    $this->get($this->base.'/verifica/'.$this->mario->id)->assertForbidden();
});

it('lascia alla segreteria il link d\'accesso per chi non ha email', function (): void {
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $guest = $this->orgContext()->run($this->org->id, fn () => Person::create(['type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Bianchi', 'host_person_id' => $this->mario->id]));
    $anna = $this->orgContext()->run($this->org->id, fn () => Person::create(['first_name' => 'Anna', 'last_name' => 'Blu']));

    $link = (string) $this->actingAs($op)->postJson('/gestionale/soci/'.$anna->id.'/area-soci/link')->assertOk()->json('link');
    expect($link)->toStartWith($this->base.'/entra/');
    $this->postJson('/gestionale/soci/'.$guest->id.'/area-soci/link')->assertStatus(422);

    $path = substr($link, strlen('http://'.$this->org->slug.'.associazionesemplice.it'));
    $this->get('http://'.$this->org->slug.'.associazionesemplice.it'.$path)->assertOk();
    $cookie = (string) $this->post('http://'.$this->org->slug.'.associazionesemplice.it'.$path)->assertRedirect('/area')->getCookie('as_socio', false)?->getValue();
    asMember($this, $cookie)->get($this->base)->assertInertia(fn (Assert $page) => $page->where('card.first_name', 'Anna')->where('card.active', false));

    $this->actingAs($op)->postJson('/gestionale/soci/'.$anna->id.'/area-soci/esci')->assertJson(['closed' => 1]);
    asMember($this, $cookie)->get($this->base)->assertRedirect('/area/accesso');

    memberArea(false);
    $this->actingAs($op)->postJson('/gestionale/soci/'.$anna->id.'/area-soci/link')->assertForbidden();
});

it('l\'email di accesso parte anche senza i contatti della segreteria, senza indirizzo di risposta', function (): void {
    $this->orgContext()->run($this->org->id, fn () => app(OrgSettings::class)->set('ente.phone', null));
    Mail::fake();
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso')->assertSessionHas('member_login_message');
    Mail::assertSent(LoginLink::class, fn (LoginLink $m): bool => $m->hasTo('mario@example.com') && ! $m->hasReplyTo('segreteria@alfa.example') && $m->contactEmail === null);
});

it('l\'email di accesso ha la segreteria come indirizzo di risposta', function (): void {
    Mail::fake();
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com']);
    Mail::assertSent(LoginLink::class, fn (LoginLink $m): bool => $m->hasReplyTo('segreteria@alfa.example') && $m->contactPhone === '06 1234567');
});

it('sul dominio personalizzato verificato l\'area soci risponde, e link e codice restano su quel dominio', function (): void {
    DB::table('core.org_domains')->insert(['org_id' => $this->org->id, 'domain' => 'soci.alfa-sport.it', 'state' => 'active']);
    $base = 'http://soci.alfa-sport.it/area';

    Mail::fake();
    $this->post($base.'/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso');
    $mail = null;
    Mail::assertSent(LoginLink::class, function (LoginLink $m) use (&$mail): bool {
        $mail = $m;

        return true;
    });
    expect($mail->link)->toStartWith($base.'/entra/');

    $cookie = (string) $this->post($base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => $mail->code])->assertRedirect('/area')->getCookie('as_socio', false)?->getValue();
    asMember($this, $cookie)->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Tessera'));
});

it('un dominio personalizzato non verificato, o di un\'altra associazione, non apre l\'area soci', function (): void {
    DB::table('core.org_domains')->insert(['org_id' => $this->org->id, 'domain' => 'soci.alfa-sport.it', 'state' => 'pending']);

    $this->get('http://soci.alfa-sport.it/area/accesso')->assertNotFound();
    $this->get('http://non-registrato.example/area/accesso')->assertNotFound();
});

it('il link d\'accesso preparato dalla segreteria usa il dominio proprio se verificato, altrimenti quello standard', function (): void {
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $anna = $this->orgContext()->run($this->org->id, fn () => Person::create(['first_name' => 'Anna', 'last_name' => 'Blu']));
    DB::table('core.org_domains')->insert(['org_id' => $this->org->id, 'domain' => 'soci.alfa-sport.it', 'state' => 'pending']);

    expect((string) $this->actingAs($op)->postJson('/gestionale/soci/'.$anna->id.'/area-soci/link')->assertOk()->json('link'))->toStartWith($this->base.'/entra/');

    DB::table('core.org_domains')->where('org_id', $this->org->id)->update(['state' => 'active']);
    $link = (string) $this->postJson('/gestionale/soci/'.$anna->id.'/area-soci/link')->assertOk()->json('link');
    expect($link)->toStartWith('http://soci.alfa-sport.it/area/entra/');
    $this->get($link)->assertOk();
    $this->post($link)->assertRedirect('/area');
});

it('il link di verifica della tessera resta sul dominio proprio quando il socio è entrato da lì', function (): void {
    DB::table('core.org_domains')->insert(['org_id' => $this->org->id, 'domain' => 'soci.alfa-sport.it', 'state' => 'active']);
    $verify = URL::signedRoute('custom.member.verify', ['host' => 'soci.alfa-sport.it', 'person' => $this->mario->id]);

    expect($verify)->toStartWith('http://soci.alfa-sport.it/area/verifica/');
    $this->get($verify)->assertOk()->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Verifica'));
});
