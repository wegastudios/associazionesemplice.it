<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Mail\LoginLink;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\GuardianService;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
    $this->get($this->base.'/entra/'.substr($mail->link, strrpos($mail->link, '/') + 1))->assertRedirect('/area/accesso');
    $this->post($this->base.'/accesso', ['email' => 'mario@example.com']);
    $links = [];
    Mail::assertSent(LoginLink::class, function (LoginLink $m) use (&$links): bool {
        $links[] = $m->link;

        return true;
    });
    $token = substr((string) end($links), strrpos((string) end($links), '/') + 1);
    $this->get($this->base.'/entra/'.$token)->assertRedirect('/area');
    $this->get($this->base.'/entra/'.$token)->assertRedirect('/area/accesso');

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
    $url = Illuminate\Support\Facades\URL::signedRoute('member.verify', ['org' => $this->org->slug, 'person' => $this->mario->id]);
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
    $cookie = (string) $this->get('http://'.$this->org->slug.'.associazionesemplice.it'.$path)->assertRedirect('/area')->getCookie('as_socio', false)?->getValue();
    asMember($this, $cookie)->get($this->base)->assertInertia(fn (Assert $page) => $page->where('card.first_name', 'Anna')->where('card.active', false));

    $this->actingAs($op)->postJson('/gestionale/soci/'.$anna->id.'/area-soci/esci')->assertJson(['closed' => 1]);
    asMember($this, $cookie)->get($this->base)->assertRedirect('/area/accesso');

    memberArea(false);
    $this->actingAs($op)->postJson('/gestionale/soci/'.$anna->id.'/area-soci/link')->assertForbidden();
});
