<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Payments\PayPalGateway;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Payments\ActivityDues;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Contabilita\Models\Transaction;
use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Models\Person;

// Opzione fiscale, spenta di default: eventi e corsi dei soci incassati come erogazione liberale.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->owner, OrgRole::Owner);
    $this->orgContext()->enter($this->org->id);
    $this->cash = Account::query()->where('type', 'cash')->sole()->id;
});

function donationEvent(): Activity
{
    return app(ActivityService::class)->save(null, ['name' => 'Cena sociale', 'kind' => 'event', 'social_year' => now()->format('Y'), 'fee_cents' => 2500, 'starts_on' => now()->toDateString()]);
}

function eventKind(Activity $a): string
{
    return Category::query()->findOrFail(Transaction::query()->where('activity_id', $a->id)->sole()->category_id)->kind->value;
}

it('spenta di default: il socio paga un contributo', function (): void {
    expect(app(OrgFiscal::class)->memberEventsDonation())->toBeFalse();
    $s = app(ActivityService::class);
    $e = donationEvent();
    $anna = Person::create(['type' => 'ordinary', 'first_name' => 'Anna', 'last_name' => 'Rossi']);
    $s->enroll($e->id, $anna->id, now()->format('Y-m'));
    expect($s->paysAsDonation($anna->id))->toBeFalse();
    $s->collect($e->id, $anna->id, $this->cash, now()->toDateString(), []);

    expect(eventKind($e))->toBe('activity_fee');
});

it('accesa: il socio paga un\'erogazione liberale, senza IVA', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    app(OrgSettings::class)->set(OrgFiscal::MEMBER_EVENTS_DONATION, true);
    $s = app(ActivityService::class);
    $e = donationEvent();
    $anna = Person::create(['type' => 'ordinary', 'first_name' => 'Anna', 'last_name' => 'Rossi']);
    $s->enroll($e->id, $anna->id, now()->format('Y-m'));
    expect($s->paysAsDonation($anna->id))->toBeTrue();
    $s->collect($e->id, $anna->id, $this->cash, now()->toDateString(), []);

    $tx = Transaction::query()->where('activity_id', $e->id)->sole();
    expect(eventKind($e))->toBe('donation')->and($tx->vat_rate)->toBeNull()->and($tx->amount_cents)->toBe(2500);
});

it('accesa: la persona che non è socia paga ancora un contributo', function (): void {
    app(OrgSettings::class)->set(OrgFiscal::MEMBER_EVENTS_DONATION, true);
    $s = app(ActivityService::class);
    $e = donationEvent();
    $marco = Person::create(['type' => 'guardian', 'first_name' => 'Marco', 'last_name' => 'Rossi']);
    $s->enroll($e->id, $marco->id, now()->format('Y-m'));
    expect($s->paysAsDonation($marco->id))->toBeFalse();
    $s->collect($e->id, $marco->id, $this->cash, now()->toDateString(), []);

    expect(eventKind($e))->toBe('activity_fee');
});

it('con PayPal la voce del socio è indicata come donazione', function (): void {
    $payload = PayPalGateway::orderPayload([
        ['label' => 'Cena', 'amount_cents' => 2500, 'donation' => true],
        ['label' => 'Gita', 'amount_cents' => 1000],
    ], 'p1', 'https://x.test/ok', 'https://x.test/no', 'Alfa');
    $items = $payload['purchase_units'][0]['items'];

    expect($items[0]['category'])->toBe('DONATION')->and($items[1])->not->toHaveKey('category');
});

it('le voci da pagare dei soci portano il segno della donazione solo con l\'opzione accesa', function (): void {
    $s = app(ActivityService::class);
    $e = donationEvent();
    $anna = Person::create(['type' => 'ordinary', 'first_name' => 'Anna', 'last_name' => 'Rossi']);
    $s->enroll($e->id, $anna->id, now()->format('Y-m'));
    $dues = app(ActivityDues::class);
    expect($dues->dues([$anna->id])[0]['donation'])->toBeFalse();
    app(OrgSettings::class)->set(OrgFiscal::MEMBER_EVENTS_DONATION, true);

    expect($dues->dues([$anna->id])[0]['donation'])->toBeTrue();
});

it('l\'opzione si accende e si spegne dall\'aspetto fiscale', function (): void {
    $this->actingAs($this->owner)->put('/gestionale/aspetto-fiscale/erogazioni-liberali', ['member_events_donation' => true])->assertRedirect();
    expect(app(OrgFiscal::class)->memberEventsDonation())->toBeTrue();
    $this->actingAs($this->owner)->put('/gestionale/aspetto-fiscale/erogazioni-liberali', ['member_events_donation' => false])->assertRedirect();

    expect(app(OrgFiscal::class)->memberEventsDonation())->toBeFalse();
});
