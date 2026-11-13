<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Contracts\PeopleError;
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
