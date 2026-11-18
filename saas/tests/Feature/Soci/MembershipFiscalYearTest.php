<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Services\MembershipRealigner;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\PeopleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Circolo Delta');
    $this->orgContext()->enter($this->org->id);
    $this->people = app(PeopleService::class);
    $this->memberships = app(MembershipService::class);
    $this->cash = Account::query()->sole()->id;
    $this->travelTo('2026-03-10');
});

/** Tessera «vecchia», com'era prima della decisione: scritta direttamente, con le date dell'anno sociale di allora. */
function legacyCard(string $orgId, string $personId, string $label, string $from, string $to, string $createdAt): string
{
    $id = Str::uuid7()->toString();
    DB::table('app.memberships')->insert([
        'org_id' => $orgId, 'id' => $id, 'person_id' => $personId, 'social_year' => $label, 'valid_from' => $from, 'valid_to' => $to,
        'source' => 'manual', 'created_at' => $createdAt,
    ]);

    return $id;
}

it('la tessera nuova segue l\'anno fiscale, non quello delle attività', function (): void {
    app(OrgSettings::class)->set(OrgFiscal::FISCAL_START_MONTH, 9);
    app(OrgSettings::class)->set(MembershipService::START_MONTH, 1);

    expect($this->memberships->membershipYearLabel('2026-03-10'))->toBe('2025/2026')
        ->and($this->memberships->membershipYearLabel('2026-09-01'))->toBe('2026/2027')
        ->and($this->memberships->socialYearLabel('2026-03-10'))->toBe('2026')
        ->and($this->memberships->membershipYearInfo('2025/2026'))->toMatchArray(['start' => '2025-09-01', 'end' => '2026-08-31'])
        ->and($this->memberships->socialYearInfo('2026'))->toMatchArray(['start' => '2026-01-01', 'end' => '2026-12-31']);
});

it('riallinea le tessere già emesse all\'anno fiscale e lascia stare quelle degli anni chiusi', function (): void {
    $anna = $this->people->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $luisa = $this->people->create(['first_name' => 'Luisa', 'last_name' => 'Bianchi']);
    // Anno fiscale 2024 chiuso (dopo la chiusura il mese non cambia più): la tessera che lo tocca resta com'è.
    DB::table('app.fiscal_years')->insert(['org_id' => $this->org->id, 'year' => 2024, 'starts_on' => '2024-01-01', 'ends_on' => '2024-12-31', 'status' => 'closed', 'closed_at' => now()]);
    $open = legacyCard($this->org->id, $anna->id, '2025/2026', '2025-09-01', '2026-08-31', '2025-09-05 10:00:00');
    $closed = legacyCard($this->org->id, $luisa->id, '2023/2024', '2023-09-01', '2024-08-31', '2023-09-05 10:00:00');

    $result = app(MembershipRealigner::class)->realign();

    $row = DB::table('app.memberships')->where('id', $open)->first();
    expect($result)->toBe(['moved' => 1, 'superseded' => 0])
        ->and([$row->social_year, (string) $row->valid_from, (string) $row->valid_to])->toBe(['2026', '2026-01-01', '2026-12-31'])
        ->and(DB::table('app.memberships')->where('id', $closed)->value('social_year'))->toBe('2023/2024')
        ->and($this->memberships->isActive($anna->id, '2026-12-31'))->toBeTrue()
        ->and(app(MembershipRealigner::class)->realign())->toBe(['moved' => 0, 'superseded' => 0]);
});

it('con due tessere della stessa annualità resta la più recente e l\'altra diventa sostituita', function (): void {
    $anna = $this->people->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $old = legacyCard($this->org->id, $anna->id, '2025/2026', '2025-09-01', '2026-08-31', '2025-09-05 10:00:00');
    $new = legacyCard($this->org->id, $anna->id, '2026', '2026-01-01', '2026-12-31', '2026-02-01 10:00:00');

    $result = app(MembershipRealigner::class)->realign();

    $history = collect($this->memberships->history($anna->id))->keyBy('id');
    expect($result)->toBe(['moved' => 0, 'superseded' => 1])
        ->and($history[$old]['valid'])->toBeFalse()
        ->and($history[$old]['replaced_by'])->toBe('2026')
        ->and($history[$new]['valid'])->toBeTrue()
        ->and(DB::table('app.memberships')->where('id', $old)->value('voided_at'))->toBeNull()
        ->and($this->memberships->holds($anna->id, '2026'))->toBeTrue()
        ->and($this->memberships->activeCount('2026-03-10'))->toBe(1);
});
