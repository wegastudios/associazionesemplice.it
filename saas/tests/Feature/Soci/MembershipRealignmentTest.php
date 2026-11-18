<?php

declare(strict_types=1);

use App\Modules\Contabilita\Setup\FiscalYearStep;
use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Services\MembershipRealigner;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\PeopleImportService;
use App\Modules\Soci\Services\PeopleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Circolo Delta');
    $this->orgContext()->enter($this->org->id);
    $this->people = app(PeopleService::class);
    $this->memberships = app(MembershipService::class);
    $this->realigner = app(MembershipRealigner::class);
    $this->travelTo('2026-03-10');
});

/** Tessera «vecchia», com'era con un altro anno: scritta direttamente, con le date dell'anno di allora. */
function oldCard(string $orgId, string $personId, string $label, string $from, string $to, string $createdAt): string
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

it('l\'anteprima dice quante tessere cambiano e non scrive nulla', function (): void {
    $anna = $this->people->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $id = oldCard($this->org->id, $anna->id, '2025/2026', '2025-09-01', '2026-08-31', '2025-09-05 10:00:00');

    $preview = $this->realigner->preview(1);

    expect($preview)->toBe(['moved' => 1, 'superseded' => 0, 'lines' => ['Rossi Anna: 2025/2026 → 2026'], 'more' => 0])
        ->and(DB::table('app.memberships')->where('id', $id)->value('social_year'))->toBe('2025/2026')
        ->and($this->realigner->preview(9))->toBe(['moved' => 0, 'superseded' => 0, 'lines' => [], 'more' => 0]);
});

it('alla conferma riallinea le tessere degli anni aperti e scrive nel registro etichetta e date di prima e di dopo', function (): void {
    $anna = $this->people->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $id = oldCard($this->org->id, $anna->id, '2025/2026', '2025-09-01', '2026-08-31', '2025-09-05 10:00:00');

    expect($this->realigner->apply(1))->toBe(['moved' => 1, 'superseded' => 0]);

    $row = DB::table('app.memberships')->where('id', $id)->first();
    $log = DB::table('audit.log')->where('action', 'membership.realigned')->where('subject_id', $id)->sole();
    $details = json_decode((string) $log->details, true);
    expect([$row->social_year, (string) $row->valid_from, (string) $row->valid_to])->toBe(['2026', '2026-01-01', '2026-12-31'])
        ->and($details)->toMatchArray(['from_year' => '2025/2026', 'from_valid_from' => '2025-09-01', 'from_valid_to' => '2026-08-31'])
        ->and($details)->toMatchArray(['to_year' => '2026', 'to_valid_from' => '2026-01-01', 'to_valid_to' => '2026-12-31'])
        ->and($this->realigner->apply(1))->toBe(['moved' => 0, 'superseded' => 0]);
});

it('non tocca le tessere che toccano un anno fiscale chiuso', function (): void {
    $luisa = $this->people->create(['first_name' => 'Luisa', 'last_name' => 'Bianchi']);
    DB::table('app.fiscal_years')->insert(['org_id' => $this->org->id, 'year' => 2024, 'starts_on' => '2024-01-01', 'ends_on' => '2024-12-31', 'status' => 'closed', 'closed_at' => now()]);
    $closed = oldCard($this->org->id, $luisa->id, '2023/2024', '2023-09-01', '2024-08-31', '2023-09-05 10:00:00');

    expect($this->realigner->preview(1)['moved'])->toBe(0)
        ->and($this->realigner->apply(1))->toBe(['moved' => 0, 'superseded' => 0])
        ->and(DB::table('app.memberships')->where('id', $closed)->value('social_year'))->toBe('2023/2024');
});

it('con due tessere della stessa annualità resta la più recente e l\'altra diventa sostituita, fuori dal libro soci', function (): void {
    $anna = $this->people->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $old = oldCard($this->org->id, $anna->id, '2025/2026', '2025-09-01', '2026-08-31', '2025-09-05 10:00:00');
    $new = oldCard($this->org->id, $anna->id, '2026', '2026-01-01', '2026-12-31', '2026-02-01 10:00:00');

    expect($this->realigner->preview(1))->toMatchArray(['moved' => 0, 'superseded' => 1, 'lines' => ['Rossi Anna: 2025/2026 sostituita dalla 2026']])
        ->and($this->realigner->apply(1))->toBe(['moved' => 0, 'superseded' => 1]);

    $history = collect($this->memberships->history($anna->id))->keyBy('id');
    $log = DB::table('audit.log')->where('action', 'membership.superseded')->where('subject_id', $old)->sole();
    expect($history[$old]['valid'])->toBeFalse()
        ->and($history[$old]['replaced_by'])->toBe('2026')
        ->and($history[$new]['valid'])->toBeTrue()
        ->and(DB::table('app.memberships')->where('id', $old)->value('voided_at'))->toBeNull()
        ->and(json_decode((string) $log->details, true))->toMatchArray(['social_year' => '2025/2026', 'valid_from' => '2025-09-01', 'valid_to' => '2026-08-31', 'replaced_by_year' => '2026'])
        ->and($this->memberships->holds($anna->id, '2026'))->toBeTrue()
        ->and($this->memberships->activeCount('2026-03-10'))->toBe(1);
});

it('il passo dell\'anno fiscale chiede la conferma sulle tessere solo se qualcosa cambia', function (): void {
    $step = app(FiscalYearStep::class);
    $anna = $this->people->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    app(OrgSettings::class)->set(OrgFiscal::FISCAL_START_MONTH, 9);
    oldCard($this->org->id, $anna->id, '2025/2026', '2025-09-01', '2026-08-31', '2025-09-05 10:00:00');

    expect($step->errors(['start_month' => 1, 'confirm_move' => true]))->toBe(['Conferma cosa succede alle tessere, oppure lascia il mese com\'era.'])
        ->and($step->errors(['start_month' => 1, 'confirm_move' => true, 'confirm_cards' => true]))->toBe([])
        ->and($step->errors(['start_month' => 9, 'confirm_move' => true]))->toBe([]);

    $step->save(['start_month' => 1, 'confirm_move' => true, 'confirm_cards' => true]);

    expect(DB::table('app.memberships')->where('person_id', $anna->id)->value('social_year'))->toBe('2026')
        ->and(session('setup_notice'))->toBe('Mese dell\'anno fiscale cambiato. Tessere riallineate: 1. Tessere sostituite: 0.');
});

it('l\'importazione dice quando una colonna anno diventa un\'altra etichetta', function (): void {
    $import = app(PeopleImportService::class);
    $path = (string) tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, "Nome;Cognome;Email;2025\nMario;Rossi;mario@example.com;x\n");

    expect($import->yearsNote($import->preview($path, 'soci.csv', MemberType::Ordinary)['plan'] ?? []))->toBe(['count' => 0, 'examples' => []]);

    app(OrgSettings::class)->set(OrgFiscal::FISCAL_START_MONTH, 9);

    expect($import->yearsNote($import->preview($path, 'soci.csv', MemberType::Ordinary)['plan'] ?? []))->toBe(['count' => 1, 'examples' => ['2025 → 2025/2026']]);
});
