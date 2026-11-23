<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MemberLimit;
use App\Modules\Soci\Services\PeopleService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->enter($this->org->id);
    $this->limit = fn (?int $limit) => app()->instance(Entitlements::class, new class($limit) implements Entitlements
    {
        public function __construct(private ?int $limit) {}

        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 0, 5, 5, [], null, $this->limit, 'piccola', 1);
        }
    });
    $this->add = function (int $n, string $type = 'ordinary'): void {
        for ($i = 0; $i < $n; $i++) {
            app(PeopleService::class)->create(['first_name' => 'Socio'.uniqid(), 'last_name' => 'Prova', 'type' => $type]);
        }
    };
});

it('senza tetto nei diritti non limita nessuno', function (): void {
    ($this->limit)(null);
    ($this->add)(3);

    expect(app(MemberLimit::class)->status()['state'])->toBe('none');
});

it('avvisa all\'80%, tollera fino al +10% per 15 giorni e poi blocca solo i nuovi soci', function (): void {
    ($this->limit)(10);
    $status = fn () => app(MemberLimit::class)->status();

    ($this->add)(8);
    expect($status()['state'])->toBe('warn')
        ->and($status()['message'])->toBe('Hai 8 soci su 10 del piano Piccola. Quando arrivi al limite, per aggiungerne altri serve il piano Media.');

    ($this->add)(2);
    $s = $status();
    expect($s['state'])->toBe('tolerance')->and($s['tolerated'])->toBe(11)->and($s['message'])->toContain('fino a 11 per 15 giorni');

    // Nella tolleranza si aggiunge ancora, fino a 11.
    ($this->add)(1);

    // Dopo i 15 giorni: nessun nuovo socio, ma quelli che ci sono restano.
    $later = CarbonImmutable::today()->addDays(16);
    expect(app(MemberLimit::class)->status($later)['state'])->toBe('blocked');
    $this->travelTo($later);
    expect(fn () => ($this->add)(1))->toThrow(PeopleError::class, 'Hai raggiunto i 10 soci del piano Piccola');
});

it('blocca i nuovi soci appena superato il +10% e libera tutto se si scende sotto il limite', function (): void {
    ($this->limit)(10);
    ($this->add)(11);

    expect(fn () => ($this->add)(1))->toThrow(PeopleError::class);
    // Gli ospiti e le altre figure non sono soci: si possono ancora creare (i primi 10% del limite sono liberi).
    app(PeopleService::class)->create(['first_name' => 'Tutore', 'last_name' => 'Prova', 'type' => 'guardian']);

    ($this->limit)(100);
    expect(app(MemberLimit::class)->status()['state'])->toBe('ok')
        ->and(app(OrgSettings::class)->get(MemberLimit::REACHED_ON))->toBeNull();
});

it('blocca anche il passaggio da non socio a socio oltre il limite, ma non le altre modifiche', function (): void {
    ($this->limit)(10);
    ($this->add)(11);
    $people = app(PeopleService::class);
    $guardian = $people->create(['first_name' => 'Tutore', 'last_name' => 'Prova', 'type' => 'guardian']);

    expect(fn () => $people->update($guardian->id, ['type' => 'ordinary']))->toThrow(PeopleError::class, 'Hai raggiunto i 10 soci del piano Piccola');
    expect($guardian->fresh()?->type->value)->toBe('guardian');

    // Chi è già socio può essere modificato, anche cambiando tipo tra soci.
    $member = Person::query()->where('type', 'ordinary')->firstOrFail();
    expect($people->update($member->id, ['type' => 'volunteer'])->type->value)->toBe('volunteer');

    // Sotto il limite il passaggio è ammesso.
    ($this->limit)(100);
    expect(app(PeopleService::class)->update($guardian->id, ['type' => 'ordinary'])->type->value)->toBe('ordinary');
});

/*
 * allowance() (gli import contano in memoria) e status() (le schede a mano) applicano le stesse regole: sugli stessi casi,
 * allowance() dice quanti nuovi soci si possono aggiungere, e aggiungendone esattamente tanti status() passa a «bloccato»
 * con lo stesso messaggio, non prima.
 */
function memberLimitSameAnswer(object $test): void
{
    (function (): void {
        $limit = app(MemberLimit::class);
        $a = $limit->allowance();
        $s = $limit->status();
        if ($s['limit'] === null) {
            expect($a['left'])->toBeNull()->and($s['state'])->toBe('none');

            return;
        }
        expect($a['left'] === 0)->toBe($s['state'] === 'blocked', 'allowance e status non concordano sul blocco');
        for ($i = 0; $i < (int) $a['left']; $i++) {
            expect($limit->status()['state'])->not->toBe('blocked', "bloccato dopo {$i} soci, allowance ne ammetteva {$a['left']}");
            ($this->add)(1);
        }
        expect($limit->status()['state'])->toBe('blocked', "dopo {$a['left']} soci status non blocca")
            ->and($limit->status()['message'])->toBe($a['message']);
    })->call($test);
}

it('allowance e status concordano: piano senza limite', function (): void {
    ($this->limit)(null);
    ($this->add)(3);
    memberLimitSameAnswer($this);
});

it('allowance e status concordano: sotto il limite, con genitori e tutori che non contano', function (): void {
    ($this->limit)(10);
    ($this->add)(5);
    ($this->add)(4, 'guardian');
    expect(app(MemberLimit::class)->allowance()['left'])->toBe(6);
    memberLimitSameAnswer($this);
});

it('allowance e status concordano: al limite, nella tolleranza', function (): void {
    ($this->limit)(10);
    ($this->add)(10);
    expect(app(MemberLimit::class)->allowance()['left'])->toBe(1);
    memberLimitSameAnswer($this);
});

it('allowance e status concordano: oltre la tolleranza', function (): void {
    ($this->limit)(10);
    ($this->add)(11);
    expect(app(MemberLimit::class)->allowance()['left'])->toBe(0);
    memberLimitSameAnswer($this);
});

it('allowance e status concordano: al limite con i giorni di tolleranza passati', function (): void {
    ($this->limit)(10);
    ($this->add)(10);
    app(MemberLimit::class)->status(); // ricorda il giorno in cui si è arrivati al limite
    $this->travelTo(CarbonImmutable::today()->addDays(16));
    expect(app(MemberLimit::class)->allowance()['left'])->toBe(0);
    memberLimitSameAnswer($this);
});

it('allowance e status concordano: gli ospiti dell\'anno oltre la quota libera contano come soci', function (): void {
    ($this->limit)(10);
    ($this->add)(8);
    $host = (string) Person::query()->value('id');
    for ($i = 0; $i < 3; $i++) {
        app(PeopleService::class)->create(['first_name' => 'Ospite'.$i, 'last_name' => 'Prova', 'type' => 'guest', 'host_person_id' => $host, 'phone' => '333 123456'.$i]);
    }
    // 8 soci e 3 ospiti, uno libero (10%): ne contano 10; con un socio in più si arriva alla tolleranza.
    expect(app(MemberLimit::class)->status()['count'])->toBe(10)->and(app(MemberLimit::class)->allowance()['left'])->toBe(1);
    memberLimitSameAnswer($this);
});
