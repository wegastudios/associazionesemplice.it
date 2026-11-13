<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\GuardianService;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\PeopleExportService;
use App\Modules\Soci\Services\PeopleImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
function importFile(string $csv, string $name = 'soci.csv'): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents((string) $path, $csv);

    return (string) $path;
}

/** @param  list<list<string>>  $rows */
function xlsxUpload(array $rows): UploadedFile
{
    $cells = '';
    foreach ($rows as $i => $row) {
        $cells .= '<row r="'.($i + 1).'">';
        foreach ($row as $j => $v) {
            $cells .= '<c r="'.chr(65 + $j).($i + 1).'" t="inlineStr"><is><t>'.htmlspecialchars($v).'</t></is></c>';
        }
        $cells .= '</row>';
    }
    $path = sys_get_temp_dir().'/soci-'.bin2hex(random_bytes(4)).'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Soci" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$cells.'</sheetData></worksheet>');
    $zip->close();

    return new UploadedFile($path, 'soci.xlsx', null, null, true);
}

beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
});

it('importa soci, ospiti, minori con tutore e anni di iscrizione', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $year = (int) now()->format('Y');
        $prev = $year - 1;
        $csv = "Tessera;Tipo;Nome;Cognome;Email;Cellulare;Codice fiscale;Data di nascita;Indirizzo;CAP;Comune;Provincia;Ospite di;Tutore;{$prev};{$year}\n"
            ."1;ordinario;Mario;Rossi;mario@example.com;333 1234567;RSSMRA80A01H501U;01/01/1980;Via Roma 1;00100;Roma;rm;;;x;x\n"
            .";genitore;Anna;Verdi;anna@example.com;339 7654321;;1985-05-06;;;;;;;;\n"
            ."2;ordinario;Luigi;Verdi;;;VRDLGU10A01H501X;01/01/2014;;;;;;anna@example.com;;x\n"
            .";ospite;Gino;Bianchi;;347 1112223;;;;;;;1;;;\n"
            .";ordinario;Senza;Contatti;;;;;;;;;;;;\n";

        $s = app(PeopleImportService::class);
        $preview = $s->preview(importFile($csv), 'soci.csv', MemberType::Ordinary);
        expect(array_column($preview['plan'] ?? [], 'action'))->toBe(['create', 'create', 'create', 'create', 'error'])
            ->and(Person::query()->count())->toBe(0);

        $r = $s->apply($preview['plan'] ?? [], false);
        expect($r)->toMatchArray(['created' => 4, 'updated' => 0, 'memberships' => 3, 'guardians' => 1, 'failed' => []]);

        $mario = Person::query()->where('card_number', '1')->sole();
        $luigi = Person::query()->where('card_number', '2')->sole();
        $gino = Person::query()->where('first_name', 'Gino')->sole();
        expect($mario->tax_code)->toBe('RSSMRA80A01H501U')
            ->and($mario->address)->toBe(['text' => 'Via Roma 1, 00100 Roma (RM)'])
            ->and(array_column(app(MembershipService::class)->history($mario->id), 'social_year'))->toBe([(string) $year, (string) $prev])
            ->and(app(MembershipService::class)->isActive($luigi->id))->toBeTrue()
            ->and(app(GuardianService::class)->of($luigi->id)[0]['name'])->toBe('Anna Verdi')
            ->and($gino->type)->toBe(MemberType::Guest)->and($gino->host_person_id)->toBe($mario->id)
            ->and(DB::table('audit.log')->where('action', 'people.imported')->exists())->toBeTrue();
    });
});

it('aggiorna chi c\'è già senza cancellare i dati che il file non ha', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'tax_code' => 'RSSMRA80A01H501U', 'phone' => '333 1234567']);
        $s = app(PeopleImportService::class);
        $preview = $s->preview(importFile("Nome;Cognome;Email;Tessera\nMario;Rossi;mario@example.com;15\n"), 'x.csv', MemberType::Ordinary);
        expect($preview['plan'][0]['message'] ?? '')->toContain('per email');

        $r = $s->apply($preview['plan'] ?? [], true);
        $p->refresh();
        expect($r['updated'])->toBe(1)->and($r['memberships'])->toBe(1)
            ->and($p->card_number)->toBe('15')->and($p->tax_code)->toBe('RSSMRA80A01H501U')->and($p->phone)->toBe('333 1234567')
            ->and(app(MembershipService::class)->isActive($p->id))->toBeTrue();

        // Di nuovo lo stesso file: nessuna tessera doppia.
        expect($s->apply($s->preview(importFile("Nome;Cognome;Email\nMario;Rossi;mario@example.com\n"), 'x.csv', MemberType::Ordinary)['plan'] ?? [], true)['memberships'])->toBe(0);
    });
});

it('salta le righe che il servizio rifiuta e lo dice', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(PeopleImportService::class);
        $plan = $s->preview(importFile("Nome;Cognome;Email;Codice fiscale\nMario;Rossi;mario@example.com;RSSMRA80A01H501X\nAnna;Neri;anna@example.com;\n"), 'x.csv', MemberType::Ordinary)['plan'] ?? [];
        $r = $s->apply($plan, false);
        expect($r['created'])->toBe(1)->and($r['failed'])->toHaveCount(1)->and($r['failed'][0])->toContain('riga 2')->toContain('codice fiscale');
    });
});

it('legge un file Excel e rifiuta i vecchi .xls', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(PeopleImportService::class);
        $file = xlsxUpload([['Elenco soci'], ['Nome', 'Cognome', 'Email'], ['Eva', 'Blu', 'eva@example.com']]);
        $p = $s->preview((string) $file->getRealPath(), 'soci.xlsx', MemberType::Volunteer);
        expect($p['plan'][0]['type'] ?? null)->toBe('volunteer')->and($p['plan'][0]['row']['line'] ?? null)->toBe(3)
            ->and($s->preview(importFile("\xD0\xCF\x11\xE0xxxx"), 'vecchio.xls', MemberType::Ordinary)['error'] ?? '')->toContain('.xls');
    });
});

it('fa anteprima e import dalla pagina, solo a chi modifica i soci', function (): void {
    $op = $this->createUser('op@example.com', twoFactor: true);
    $acc = $this->createUser('acc@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->addOperator($this->org, $acc, OrgRole::Accountant);
    $upload = fn () => UploadedFile::fake()->createWithContent('soci.csv', "Nome;Cognome;Email\nEva;Blu;eva@example.com\nSenza;Nulla;\n");

    $this->actingAs($acc)->get('/gestionale/soci/importa')->assertForbidden();
    $this->actingAs($acc)->get('/gestionale/soci/esporta')->assertForbidden();

    $this->actingAs($op)->get('/gestionale/soci/importa')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Soci/Importa')->where('preview', null));
    $this->post('/gestionale/soci/importa', ['file' => $upload(), 'default_type' => 'ordinary'])
        ->assertInertia(fn (Assert $page) => $page->where('preview.create', 1)->where('preview.error', 1)->where('preview.rows.1.message', 'Servono almeno l\'email, il cellulare o il numero di tessera'));
    $this->orgContext()->run($this->org->id, fn () => expect(Person::query()->count())->toBe(0));

    $this->post('/gestionale/soci/importa', ['file' => $upload(), 'default_type' => 'ordinary', 'apply' => true])
        ->assertInertia(fn (Assert $page) => $page->where('result.created', 1));
    $this->orgContext()->run($this->org->id, fn () => expect(Person::query()->sole()->email)->toBe('eva@example.com'));

    $this->post('/gestionale/soci/importa', ['file' => UploadedFile::fake()->createWithContent('x.csv', "Tessera;Email\n1;a@b.it"), 'default_type' => 'ordinary'])
        ->assertSessionHasErrors('file');
    $this->post('/gestionale/soci/importa', ['file' => UploadedFile::fake()->createWithContent('x.pdf', '%PDF'), 'default_type' => 'ordinary'])
        ->assertSessionHasErrors('file');
    $this->get('/gestionale/soci/modello-import.csv')->assertOk()->assertHeader('content-disposition', 'attachment; filename="modello-soci.csv"');
});

it('esporta l\'anagrafica in CSV leggibile dall\'import, e lo annota', function (): void {
    $user = $this->createUser('op@example.com', twoFactor: true);
    $this->addOperator($this->org, $user, OrgRole::Operator);
    $this->orgContext()->run($this->org->id, function (): void {
        $mario = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'tax_code' => 'RSSMRA80A01H501U', 'card_number' => '1', 'birth_date' => '1980-01-01']);
        Person::create(['first_name' => '=SOMMA(A1)', 'last_name' => 'Test', 'phone' => '+39 333 1234567', 'card_number' => '2']);
        Person::create(['type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Bianchi', 'phone' => '347 1112223', 'host_person_id' => $mario->id]);
        app(MembershipService::class)->grantManually($mario->id, now()->format('Y'), 'prova');
    });

    $response = $this->actingAs($user)->get('/gestionale/soci/esporta')->assertOk()->assertHeader('cache-control', 'no-store, private');
    $csv = (string) $response->getContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF\"Numero tessera\";Tipo;Nome;Cognome")
        ->toContain('1;"Socio ordinario";Mario;Rossi;mario@example.com;;RSSMRA80A01H501U;01/01/1980')
        ->toContain("'=SOMMA(A1)")->toContain('"+39 333 1234567"')
        ->toContain('Bianchi;;"347 1112223";;;;;;;;1;;'."\r\n");
    $this->orgContext()->run($this->org->id, fn () => expect(DB::table('audit.log')->where('action', 'export.done')->value('details'))->toContain('"rows": 3'));

    // Ricaricato, il file aggiorna le stesse schede.
    $this->orgContext()->run($this->org->id, function () use ($csv): void {
        $plan = app(PeopleImportService::class)->preview(importFile($csv), 'soci.csv', MemberType::Ordinary)['plan'] ?? [];
        expect(array_column($plan, 'action'))->toBe(['update', 'update', 'update']);
    });

    $this->get('/gestionale/soci/esporta?tipo=guest')->assertOk();
    expect(substr_count((string) $this->get('/gestionale/soci/esporta?q=rossi')->getContent(), "\r\n"))->toBe(2);
});

it('protegge le celle che Excel prenderebbe per formule', function (string $in, string $out): void {
    expect(PeopleExportService::cell($in))->toBe($out);
})->with([
    ['=1+1', "'=1+1"], ['@SUM', "'@SUM"], ['-2+3', "'-2+3"], ['+39 333 1234567', '+39 333 1234567'], ['-5', '-5'], ['Mario', 'Mario'],
]);

it('importa un socio ente e lo esporta con tipo e sezione', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $csv = "Tipo soggetto;Denominazione;Codice fiscale;Stessa sezione RUNTS;Email;Tipo\nente;Pro Loco Beta;12345678903;sì;info@beta.example.com;ordinario\n";
        $service = app(PeopleImportService::class);
        $preview = $service->preview(importFile($csv), 'soci.csv', MemberType::Ordinary);
        expect($preview)->toHaveKey('plan');
        $result = $service->apply($preview['plan'], false);

        $entity = Person::query()->sole();
        expect($result['failed'])->toBe([])->and($result['created'])->toBe(1)
            ->and($entity->is_entity)->toBeTrue()->and($entity->last_name)->toBe('Pro Loco Beta')->and($entity->first_name)->toBe('')
            ->and($entity->entity_same_section)->toBeTrue()->and($entity->tax_code)->toBe('12345678903');

        $out = app(PeopleExportService::class)->csv()['csv'];
        expect($out)->toContain('"Tipo soggetto";"Stessa sezione RUNTS"')->and($out)->toContain('Ente;sì');
    });
});
