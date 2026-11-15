<?php

declare(strict_types=1);

use App\Modules\Attivita\Contracts\Activities;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\ActivityVat;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Transaction;
use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Models\Person;
use Inertia\Testing\AssertableInertia as Assert;

// IVA di corsi ed eventi: solo con la partita IVA; le attività esistenti restano senza IVA.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->owner, OrgRole::Owner);
    $this->orgContext()->enter($this->org->id);
    $this->cash = Account::query()->where('type', 'cash')->sole()->id;
});

function vatCourse(?int $rate): Activity
{
    return app(ActivityService::class)->save(null, [
        'name' => 'Yoga', 'kind' => 'course', 'social_year' => now()->format('Y'), 'billing' => 'monthly', 'fee_cents' => 1000, 'vat_rate' => $rate,
    ]);
}

it('un corso senza IVA registra l\'incasso senza aliquota', function (): void {
    $s = app(ActivityService::class);
    $yoga = vatCourse(null);
    $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $s->enroll($yoga->id, $anna->id, now()->format('Y-m'));
    $s->collect($yoga->id, $anna->id, $this->cash, now()->toDateString(), [now()->format('Y-m')]);

    $tx = Transaction::query()->where('activity_id', $yoga->id)->sole();
    expect($tx->vat_rate)->toBeNull()->and($tx->vat_cents)->toBe(0)->and($tx->amount_cents)->toBe(1000);
});

it('con la partita IVA il corso incassa con la sua aliquota, prezzi finali', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    $s = app(ActivityService::class);
    $yoga = vatCourse(22);
    $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $s->enroll($yoga->id, $anna->id, now()->format('Y-m'));
    expect(collect($s->status($yoga))->sole()['fee'])->toBe(1000);
    $s->collect($yoga->id, $anna->id, $this->cash, now()->toDateString(), [now()->format('Y-m')]);

    $tx = Transaction::query()->where('activity_id', $yoga->id)->sole();
    expect($tx->vat_rate)->toBe(22)->and($tx->amount_cents)->toBe(1000)->and($tx->vat_cents)->toBe(180);
});

it('con i prezzi senza IVA la persona paga prezzo più IVA', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    app(OrgSettings::class)->set(OrgFiscal::INCLUDES_VAT, false);
    $s = app(ActivityService::class);
    $yoga = vatCourse(22);
    $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $s->enroll($yoga->id, $anna->id, now()->format('Y-m'));
    expect(collect($s->status($yoga))->sole()['fee'])->toBe(1220);
    $s->collect($yoga->id, $anna->id, $this->cash, now()->toDateString(), [now()->format('Y-m')]);

    expect(Transaction::query()->where('activity_id', $yoga->id)->sole()->amount_cents)->toBe(1220);
});

it('l\'evento «IVA esente» non aumenta il prezzo e registra aliquota 0', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    app(OrgSettings::class)->set(OrgFiscal::INCLUDES_VAT, false);
    $s = app(ActivityService::class);
    $cena = $s->save(null, ['name' => 'Cena', 'kind' => 'event', 'social_year' => now()->format('Y'), 'fee_cents' => 2500, 'starts_on' => now()->toDateString(), 'vat_rate' => 0]);
    $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $s->enroll($cena->id, $anna->id, now()->format('Y-m'));
    $s->collect($cena->id, $anna->id, $this->cash, now()->toDateString(), []);

    $tx = Transaction::query()->where('activity_id', $cena->id)->sole();
    expect($tx->vat_rate)->toBe(0)->and($tx->amount_cents)->toBe(2500);
});

it('se l\'aliquota non è più in elenco l\'attività si comporta come senza IVA', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    $yoga = vatCourse(22);
    app(OrgSettings::class)->set(OrgFiscal::RATES, '10');
    expect(app(ActivityVat::class)->rate($yoga))->toBeNull();
});

it('la scheda salva «Con IVA» solo con la partita IVA e dalle aliquote scelte', function (): void {
    $this->orgContext()->leave();
    $base = ['name' => 'Yoga', 'kind' => 'course', 'social_year' => now()->format('Y'), 'billing' => 'monthly', 'fee' => '10', 'resource' => 'none', 'with_vat' => true, 'vat_rate' => 22];
    $this->actingAs($this->owner)->post('/gestionale/attivita', $base)->assertSessionHasErrors('with_vat');

    $this->orgContext()->run($this->org->id, fn () => app(OrgSettings::class)->set('ente.vat', '12345678903'));
    $this->actingAs($this->owner)->post('/gestionale/attivita', $base)->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/gestionale/attivita', ['vat_rate' => 7, 'name' => 'Pilates'] + $base)->assertSessionHasErrors('vat_rate');
    expect($this->orgContext()->run($this->org->id, fn () => Activity::query()->sole()->vat_rate))->toBe(22);
});

it('non si toglie da Aspetto fiscale un\'aliquota usata da un\'attività', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    vatCourse(10);
    $this->orgContext()->leave();
    $this->actingAs($this->owner)
        ->put('/gestionale/aspetto-fiscale', ['rates' => '22', 'includes_vat' => true, 'membership_rate' => 0])
        ->assertSessionHasErrors('rates');
});

it('la scheda avvisa se l\'IVA dell\'attività non si può più applicare e i Dati dell\'ente contano le attività con IVA', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    $yoga = vatCourse(22);
    expect(app(Activities::class)->withVatCount())->toBe(1);
    app(OrgSettings::class)->set('ente.vat', '');
    $this->orgContext()->leave();
    $this->actingAs($this->owner)->get("/gestionale/attivita/{$yoga->id}")->assertInertia(fn (Assert $page) => $page->where('activity.vat_warning', 'Senza partita IVA l\'IVA non si applica: gli incassi di questa attività si registrano senza IVA.'));
});
