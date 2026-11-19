<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\ConsentChannel;
use App\Modules\Soci\Contracts\ConsentSource;
use App\Modules\Soci\Jobs\SendApplicationEmail;
use App\Modules\Soci\Jobs\SendReminderEmail;
use App\Modules\Soci\Models\Application;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\ApplicationService;
use App\Modules\Soci\Services\CertificateService;
use App\Modules\Soci\Services\ConsentService;
use App\Modules\Soci\Services\GuardianService;
use App\Modules\Soci\Services\MemberNotification;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\ReminderService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
function adultApplication(array $over = []): array
{
    return $over + [
        'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'phone' => '333 1234567', 'tax_code' => 'RSSMRA80A01H501U',
        'birth_date' => '1980-01-01', 'type' => 'ordinary', 'privacy_ok' => true, 'images_ok' => true,
    ];
}

function minorApplication(array $over = []): array
{
    return $over + [
        'first_name' => 'Luigi', 'last_name' => 'Verdi', 'tax_code' => 'VRDLGU10A01H501X', 'birth_date' => now()->subYears(12)->toDateString(), 'type' => 'ordinary',
        'privacy_ok' => true, 'guardian_ok' => true, 'guardian_first_name' => 'Anna', 'guardian_last_name' => 'Verdi',
        'guardian_email' => 'anna@example.com', 'guardian_phone' => '339 7654321',
    ];
}

beforeEach(function (): void {
    Notification::fake();
    $this->org = $this->createOrg('Polisportiva Alfa');
});

it('tiene la storia dei consensi e lo stato attuale', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $c = app(ConsentService::class);
        $p = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);

        expect($c->set($p->id, ConsentChannel::Newsletter, true, ConsentSource::Paper))->toBeTrue()
            ->and($c->set($p->id, ConsentChannel::Newsletter, true, ConsentSource::Paper))->toBeFalse()
            ->and($c->has($p->id, ConsentChannel::Newsletter))->toBeTrue();

        $c->set($p->id, ConsentChannel::Newsletter, false, ConsentSource::Oral);
        expect($c->has($p->id, ConsentChannel::Newsletter))->toBeFalse()
            ->and($c->current($p->id)['newsletter'])->toMatchArray(['granted' => false, 'source' => 'A voce'])
            ->and(DB::table('app.consent_events')->where('person_id', $p->id)->count())->toBe(2)
            ->and(DB::table('audit.log')->whereIn('action', ['consent.granted', 'consent.revoked'])->count())->toBe(2);
    });
});

it('non lascia modificare né cancellare gli eventi di consenso', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        app(ConsentService::class)->set($p->id, ConsentChannel::Images, true, ConsentSource::Paper);

        expect(fn () => DB::table('app.consent_events')->update(['granted' => false]))->toThrow(Illuminate\Database\QueryException::class);
    });
});

it('registra un consenso dalla scheda, per il minore dato dal tutore', function (): void {
    $user = $this->createUser('op@example.com', twoFactor: true);
    $this->addOperator($this->org, $user, OrgRole::Operator);
    [$minor, $mamma] = $this->orgContext()->run($this->org->id, function (): array {
        $minor = Person::create(['first_name' => 'Luca', 'last_name' => 'Rossi', 'birth_date' => now()->subYears(10)->toDateString()]);
        $mamma = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi', 'email' => 'anna@example.com', 'birth_date' => '1985-01-01']);
        app(GuardianService::class)->link($minor->id, $mamma->id);

        return [$minor->id, $mamma->id];
    });
    $other = $this->orgContext()->run($this->org->id, fn () => Person::create(['first_name' => 'X', 'last_name' => 'Y'])->id);

    $this->actingAs($user)->post("/gestionale/soci/{$minor}/consensi", ['channel' => 'images', 'granted' => true, 'source' => 'paper', 'given_by' => $other])
        ->assertSessionHasErrors('ledger');
    $this->post("/gestionale/soci/{$minor}/consensi", ['channel' => 'images', 'granted' => true, 'source' => 'web'])->assertSessionHasErrors('source');
    $this->post("/gestionale/soci/{$minor}/consensi", ['channel' => 'images', 'granted' => true, 'source' => 'paper', 'given_by' => $mamma])
        ->assertSessionHas('status', 'Uso delle immagini: consenso registrato.');

    $this->get("/gestionale/soci/{$minor}")->assertInertia(fn (Assert $page) => $page
        ->where('person.consents.images.granted', true)->where('person.consents.images.given_by', 'Anna Rossi'));
});

/** Esegue i lavori accodati come farebbe il lavoratore, fuori da ogni associazione. */
function runQueuedReminders(): void
{
    foreach (Queue::pushed(SendReminderEmail::class) as $job) {
        $job->handle(app(OrgContext::class));
    }
}

it('manda i promemoria una volta sola, al socio o al genitore del minore', function (): void {
    Queue::fake();
    $this->orgContext()->run($this->org->id, function (): void {
        app(OrgSettings::class)->set(ReminderService::ENABLED, true);
        $today = now()->toDateString();
        $adult = Person::create(['first_name' => 'Mario', 'last_name' => 'Bianchi', 'email' => 'mario@example.com', 'birth_date' => '1980-01-01']);
        $minor = Person::create(['first_name' => 'Luca', 'last_name' => 'Rossi', 'birth_date' => now()->subYears(10)->toDateString()]);
        $mamma = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi', 'email' => 'anna@example.com', 'birth_date' => '1985-01-01']);
        $noEmail = Person::create(['first_name' => 'Senza', 'last_name' => 'Email']);
        app(GuardianService::class)->link($minor->id, $mamma->id);

        // Tessera dell'anno: scade il 31/12, oltre il preavviso di 10 giorni.
        app(OrgSettings::class)->set(ReminderService::DAYS, 10);
        app(MembershipService::class)->grantManually($adult->id, now()->format('Y'), 'quota pagata in contanti');
        $certs = app(CertificateService::class);
        $certs->add($minor->id, ['kind' => 'non_agonistic', 'issued_on' => now()->subYear()->toDateString(), 'expires_on' => now()->addDays(5)->toDateString()]);
        $certs->add($noEmail->id, ['kind' => 'non_agonistic', 'issued_on' => now()->subYear()->toDateString(), 'expires_on' => now()->addDays(5)->toDateString()]);
        $certs->add($adult->id, ['kind' => 'agonistic', 'issued_on' => now()->subYear()->toDateString(), 'expires_on' => now()->subDays(3)->toDateString()]);

        $r = app(ReminderService::class);
        $first = $r->run($today);
        expect($first)->toBe(['membership_soon' => 0, 'membership_expired' => 0, 'cert_soon' => 1, 'cert_expired' => 1])
            ->and($r->run($today))->toBe(['membership_soon' => 0, 'membership_expired' => 0, 'cert_soon' => 0, 'cert_expired' => 0]);
    });

    Notification::assertNothingSent();
    runQueuedReminders();
    Notification::assertSentTo(new AnonymousNotifiable, MemberNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'anna@example.com' && str_contains($n->body, 'di Luca'));
    Notification::assertSentTimes(MemberNotification::class, 2);
});

it('il comando passa le associazioni che hanno acceso i promemoria', function (): void {
    Queue::fake();
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::create(['first_name' => 'Mario', 'last_name' => 'Bianchi', 'email' => 'mario@example.com', 'birth_date' => '1980-01-01']);
        app(CertificateService::class)->add($p->id, ['kind' => 'agonistic', 'issued_on' => now()->subYear()->toDateString(), 'expires_on' => now()->addDays(2)->toDateString()]);
    });
    $this->artisan('saas:reminders:send')->assertSuccessful();
    Notification::assertNothingSent();

    $this->orgContext()->run($this->org->id, fn () => app(OrgSettings::class)->set(ReminderService::ENABLED, true));
    $this->artisan('saas:reminders:send', ['--dry-run' => true])->assertSuccessful();
    Notification::assertNothingSent();
    $this->artisan('saas:reminders:send')->assertSuccessful();
    Notification::assertNothingSent();
    runQueuedReminders();
    Notification::assertSentTimes(MemberNotification::class, 1);
});

it('accoglie la domanda di un maggiorenne con i consensi del modulo', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(ApplicationService::class);
        expect($s->submit(adultApplication(), 'Polisportiva Alfa'))->toBe([]);
        $a = Application::query()->sole();
        expect($a->status)->toBe('pending')->and($a->data['tax_code'])->toBe('RSSMRA80A01H501U')
            ->and(DB::table('app.applications')->value('data_enc'))->not->toBeNull()
            ->and(DB::table('app.applications')->whereRaw("encode(data_enc, 'escape') LIKE '%RSSMRA%'")->exists())->toBeFalse();
        Notification::assertSentTimes(MemberNotification::class, 1);

        $personId = $s->approve($a->id, null, 'Polisportiva Alfa');
        $p = Person::query()->findOrFail($personId);
        expect($p->tax_code)->toBe('RSSMRA80A01H501U')->and($p->card_number)->toBe('1')->and($p->joined_on?->toDateString())->toBe(now()->toDateString())
            ->and(app(ConsentService::class)->has($p->id, ConsentChannel::Privacy))->toBeTrue()
            ->and(app(ConsentService::class)->has($p->id, ConsentChannel::Images))->toBeTrue()
            ->and(app(ConsentService::class)->has($p->id, ConsentChannel::Rules))->toBeFalse()
            ->and(app(ConsentService::class)->current($p->id)['privacy']['source'])->toBe('Online')
            ->and($a->refresh()->status)->toBe('approved')
            ->and(fn () => $s->approve($a->id, null, ''))->toThrow(App\Modules\Soci\Contracts\PeopleError::class, 'già decisa');
    });
});

it('accoglie la domanda di un minore collegando il genitore che ha già una scheda', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $mamma = Person::create(['type' => 'guardian', 'first_name' => 'Anna', 'last_name' => 'Verdi', 'email' => 'anna@example.com']);
        $s = app(ApplicationService::class);
        expect($s->submit(minorApplication(), 'Polisportiva Alfa'))->toBe([]);
        Notification::assertSentTo(new AnonymousNotifiable, MemberNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'anna@example.com');

        $id = $s->approve(Application::query()->sole()->id, '7', 'Polisportiva Alfa');
        $guardians = app(GuardianService::class)->of($id);
        expect($guardians)->toHaveCount(1)->and($guardians[0]['id'])->toBe($mamma->id)->and($guardians[0]['operative'])->toBeTrue()
            ->and(Person::query()->findOrFail($id)->card_number)->toBe('7')
            ->and(DB::table('app.current_consents')->where('person_id', $id)->value('given_by_person_id'))->toBe($mamma->id)
            ->and(Person::query()->count())->toBe(2);
    });
});

it('crea la scheda del genitore se non c\'è e respinge con un motivo', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(ApplicationService::class);
        $s->submit(minorApplication(), 'X');
        $s->submit(adultApplication(), 'X');
        [$first, $second] = Application::query()->orderBy('submitted_at')->orderBy('id')->get()->all();

        $id = $s->approve($first->id, null, 'X');
        $guardian = Person::query()->where('type', 'guardian')->sole();
        expect($guardian->email)->toBe('anna@example.com')->and(app(GuardianService::class)->of($id)[0]['id'])->toBe($guardian->id);

        expect(fn () => $s->reject($second->id, ' '))->toThrow(App\Modules\Soci\Contracts\PeopleError::class);
        $s->reject($second->id, 'Già iscritto con un\'altra scheda');
        expect($s->pending())->toBe([])->and(array_column($s->decided(), 'status', 'id'))->toBe([$first->id => 'approved', $second->id => 'rejected'] + array_column($s->decided(), 'status', 'id'))
            ->and(DB::table('audit.log')->where('action', 'application.rejected')->exists())->toBeTrue();
    });
});

it('mostra il modulo pubblico solo se le domande sono accese', function (): void {
    $slug = $this->org->slug;
    $this->get("/adesione/{$slug}")->assertNotFound();
    $this->get('/adesione/non-esiste')->assertNotFound();

    $this->orgContext()->run($this->org->id, fn () => app(OrgSettings::class)->set(ApplicationService::ENABLED, true));
    $this->get("/adesione/{$slug}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Soci/Adesione')
        ->where('org.name', 'Polisportiva Alfa')->where('sent', false)->has('types', 2));

    app(OrgDirectory::class)->setStatus($this->org->id, 'suspended');
    $this->get("/adesione/{$slug}")->assertNotFound();
});

it('riceve la domanda dal modulo pubblico, con errori, trappola e limite di invii', function (): void {
    $slug = $this->org->slug;
    $this->orgContext()->run($this->org->id, fn () => app(OrgSettings::class)->set(ApplicationService::ENABLED, true));
    RateLimiter::clear('application|127.0.0.1');

    $this->post("/adesione/{$slug}", adultApplication(['tax_code' => 'SBAGLIATO']))->assertSessionHasErrors('form_0');
    $this->post("/adesione/{$slug}", adultApplication(['website' => 'http://spam.example']))->assertRedirect("/adesione/{$slug}")->assertSessionHas('application_sent', $slug);
    $this->orgContext()->run($this->org->id, fn () => expect(Application::query()->count())->toBe(0));

    $this->post("/adesione/{$slug}", adultApplication())->assertRedirect("/adesione/{$slug}")->assertSessionHas('application_sent', $slug);
    $this->get("/adesione/{$slug}")->assertInertia(fn (Assert $page) => $page->where('sent', true));
    $this->orgContext()->run($this->org->id, fn () => expect(Application::query()->sole()->data['email'])->toBe('mario@example.com'));

    $this->post("/adesione/{$slug}", adultApplication());
    $this->post("/adesione/{$slug}", adultApplication()); // 5° invio del minuto: ancora accettato
    $this->post("/adesione/{$slug}", adultApplication())->assertStatus(429);
});

it('lascia accogliere le domande alla segreteria, non al commercialista', function (): void {
    $op = $this->createUser('op@example.com', twoFactor: true);
    $acc = $this->createUser('acc@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->addOperator($this->org, $acc, OrgRole::Accountant);
    $id = $this->orgContext()->run($this->org->id, function (): string {
        app(ApplicationService::class)->submit(adultApplication(), 'X');

        return Application::query()->sole()->id;
    });

    $this->actingAs($acc)->post("/gestionale/domande/{$id}/accogli")->assertForbidden();
    $this->actingAs($acc)->post('/gestionale/domande/impostazioni', [])->assertForbidden();

    $this->actingAs($op)->get('/gestionale/domande')->assertInertia(fn (Assert $page) => $page->component('Soci/Domande')->has('pending', 1)->where('canSettings', false));
    $this->get("/gestionale/domande/{$id}")->assertInertia(fn (Assert $page) => $page->component('Soci/Domanda')->where('application.data.tax_code', 'RSSMRA80A01H501U'));
    $this->post("/gestionale/domande/{$id}/accogli", ['card_number' => '12'])->assertRedirect()->assertSessionHas('status', 'Domanda accolta: scheda creata.');
    $this->post("/gestionale/domande/{$id}/respingi", ['reason' => 'x'])->assertSessionHasErrors('ledger');
});

it('salva le impostazioni di domande e promemoria', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);

    $this->actingAs($owner)->post('/gestionale/domande/impostazioni', [
        'applications_enabled' => true, 'applications_rules' => true, 'privacy_url' => 'http://insicuro.example', 'rules_url' => '',
        'reminders_enabled' => true, 'reminders_days' => 20,
    ])->assertSessionHasErrors('privacy_url');
    $this->post('/gestionale/domande/impostazioni', [
        'applications_enabled' => true, 'applications_rules' => true, 'privacy_url' => 'https://alfa.example/privacy', 'rules_url' => '',
        'reminders_enabled' => true, 'reminders_days' => 20,
    ])->assertSessionHas('status');

    $this->get('/gestionale/domande')->assertInertia(fn (Assert $page) => $page
        ->where('settings.applications_enabled', true)->where('settings.privacy_url', 'https://alfa.example/privacy')->where('settings.reminders_days', 20)
        ->where('formUrl', url('/adesione/'.$this->org->slug)));
});

it('i promemoria vanno in coda, un lavoro per destinatario, senza dati personali nel lavoro', function (): void {
    Queue::fake();
    $this->orgContext()->run($this->org->id, function (): void {
        app(OrgSettings::class)->set(ReminderService::ENABLED, true);
        $p = Person::create(['first_name' => 'Mario', 'last_name' => 'Bianchi', 'email' => 'mario@example.com', 'birth_date' => '1980-01-01']);
        app(CertificateService::class)->add($p->id, ['kind' => 'agonistic', 'issued_on' => now()->subYear()->toDateString(), 'expires_on' => now()->addDays(2)->toDateString()]);

        expect(app(ReminderService::class)->run(now()->toDateString()))->toMatchArray(['cert_soon' => 1]);
        expect(DB::table('app.reminders')->where('person_id', $p->id)->count())->toBe(1);
    });

    Notification::assertNothingSent();
    Queue::assertPushed(SendReminderEmail::class, 1);
    Queue::assertPushed(SendReminderEmail::class, fn (SendReminderEmail $job) => ! str_contains(serialize($job), 'mario@example.com'));
});

it('se il promemoria in coda non parte più, la prenotazione si toglie e il giro dopo riprova', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::create(['first_name' => 'Mario', 'last_name' => 'Bianchi', 'email' => 'mario@example.com', 'birth_date' => '1980-01-01']);
        $until = now()->addDays(2)->toDateString();
        DB::table('app.reminders')->insert(['org_id' => $this->org->id, 'person_id' => $p->id, 'kind' => 'cert_soon', 'ref' => $until]);

        (new SendReminderEmail($this->org->id, $p->id, 'cert_soon', $until, now()->toDateString()))->failed(new RuntimeException('posta non raggiungibile'));

        expect(DB::table('app.reminders')->where('person_id', $p->id)->count())->toBe(0);
    });
});

it('il limite di frequenza dei promemoria si regola da configurazione', function (): void {
    config(['saas.reminders.per_org_per_minute' => 2, 'saas.reminders.per_minute' => 5]);
    $job = new SendReminderEmail($this->org->id, '00000000-0000-7000-8000-000000000001', 'cert_soon', now()->toDateString(), now()->toDateString());
    $limits = RateLimiter::limiter('reminder-mail')($job);

    expect(array_map(static fn ($l) => $l->maxAttempts, $limits))->toBe([5, 2]);
});

it('la conferma della domanda va in coda con soli identificativi e parte con indirizzo e testo ricavati dopo', function (): void {
    Queue::fake();
    $this->orgContext()->run($this->org->id, function (): void {
        expect(app(ApplicationService::class)->submit(adultApplication(), 'Polisportiva Alfa'))->toBe([]);
    });

    Notification::assertNothingSent();
    Queue::assertPushed(SendApplicationEmail::class, 1);
    Queue::assertPushed(SendApplicationEmail::class, fn (SendApplicationEmail $job) => ! str_contains(serialize($job), 'mario@example.com') && ! str_contains(serialize($job), 'Mario'));

    foreach (Queue::pushed(SendApplicationEmail::class) as $job) {
        $job->handle(app(OrgContext::class));
    }
    Notification::assertSentOnDemand(MemberNotification::class, fn (MemberNotification $n, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'mario@example.com' && $n->greeting === 'Ciao Mario,');
});

it('l\'email di una domanda non accolta riporta i recapiti della segreteria e l\'oggetto parte dal nome dell\'associazione', function (): void {
    $org = $this->createOrg('Circolo Epsilon');
    $this->orgContext()->enter($org->id);
    app(OrgSettings::class)->set('ente.email', 'segreteria@example.com');
    app(OrgSettings::class)->set('ente.phone', '011 1234567');
    $app = Application::query()->create(['status' => 'pending', 'minor' => false, 'data' => ['first_name' => 'Anna', 'last_name' => 'Rossi', 'email' => 'anna@example.com']]);
    Notification::fake();

    app(ApplicationService::class)->sendMail($app->id, 'rejected', 'Circolo Epsilon');

    Notification::assertSentOnDemand(MemberNotification::class, function (MemberNotification $n, array $channels, object $notifiable): bool {
        $mail = $n->toMail($notifiable);

        return $mail->subject === 'Circolo Epsilon · Domanda di adesione non accolta'
            && str_contains($n->body, 'Segreteria: segreteria@example.com · tel. 011 1234567');
    });
});
