<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\MedicalCertificate;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\CertificateService;
use App\Modules\Soci\Services\GuardianService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->enter($this->org->id);
    $this->minor = Person::create(['first_name' => 'Luca', 'last_name' => 'Rossi', 'birth_date' => now()->subYears(10)->toDateString()]);
    $this->mamma = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi', 'email' => 'anna@example.com', 'birth_date' => '1985-01-01']);
    $this->papa = Person::create(['type' => 'guardian', 'first_name' => 'Marco', 'last_name' => 'Rossi']);
});

afterEach(function (): void {
    Storage::disk('org_files')->deleteDirectory('/');
});

it('collega fino a due tutori, il primo operativo, e passa l\'operativo all\'altro', function (): void {
    $g = app(GuardianService::class);

    $g->link($this->minor->id, $this->mamma->id);
    $g->link($this->minor->id, $this->papa->id); // non operativo: l'email non serve
    $third = Person::create(['first_name' => 'Nonna', 'last_name' => 'Rossi', 'email' => 'n@example.com']);

    expect(array_column($g->of($this->minor->id), 'operative', 'name'))->toBe(['Anna Rossi' => true, 'Marco Rossi' => false])
        ->and(fn () => $g->link($this->minor->id, $third->id))->toThrow(PeopleError::class, 'al massimo 2')
        ->and(fn () => $g->setOperative($this->minor->id, $this->papa->id))->toThrow(PeopleError::class, 'email')
        ->and($g->dependentsOf($this->mamma->id))->toBe([['id' => $this->minor->id, 'name' => 'Luca Rossi']]);

    $g->unlink($this->minor->id, $this->mamma->id);
    expect($g->of($this->minor->id))->toBe([['id' => $this->papa->id, 'name' => 'Marco Rossi', 'email' => null, 'operative' => true]])
        ->and(DB::table('audit.log')->whereIn('action', ['guardian.linked', 'guardian.unlinked'])->count())->toBe(3);
});

it('rifiuta ospiti come tutori e minori senza data di nascita', function (): void {
    $g = app(GuardianService::class);
    $guest = Person::create(['type' => 'guest', 'first_name' => 'O', 'last_name' => 'Ospite', 'host_person_id' => $this->mamma->id]);
    $noBirth = Person::create(['first_name' => 'Senza', 'last_name' => 'Data']);

    expect(fn () => $g->link($this->minor->id, $guest->id))->toThrow(PeopleError::class, 'ospite')
        ->and(fn () => $g->link($noBirth->id, $this->mamma->id))->toThrow(PeopleError::class, 'data di nascita');
});

it('salva il certificato cifrato, con il file cifrato, e annota ogni lettura', function (): void {
    $pdf = UploadedFile::fake()->createWithContent('certificato Luca.pdf', '%PDF-1.4 certificato agonistico di Luca Rossi');
    $cert = app(CertificateService::class)->add($this->minor->id, [
        'kind' => 'agonistic', 'issued_on' => now()->subMonth()->toDateString(), 'expires_on' => now()->addMonths(11)->toDateString(), 'doctor' => 'Dr. Bianchi',
    ], $pdf);

    $raw = (array) DB::table('app.medical_certs')->where('id', $cert->id)->first();
    $stored = implode('|', array_map(static fn ($v) => is_resource($v) ? Bytea::decode($v) : (string) $v, $raw));
    expect($stored)->not->toContain('agonistic')->not->toContain('Bianchi')->not->toContain('Luca')
        ->and(Storage::disk('org_files')->get((string) $cert->file_path))->not->toContain('agonistico');

    $readsBefore = DB::table('audit.log')->where('action', 'health.read')->count();
    $info = app(CertificateService::class)->forPerson($this->minor->id);
    expect($info['status'])->toBe('valid')
        ->and($info['items'][0])->toMatchArray(['kind' => 'Agonistico', 'doctor' => 'Dr. Bianchi', 'has_file' => true, 'current' => true])
        ->and(DB::table('audit.log')->where('action', 'health.read')->count())->toBeGreaterThan($readsBefore);

    $response = app(CertificateService::class)->download($cert->id);
    ob_start();
    $response->sendContent();
    expect((string) ob_get_clean())->toBe('%PDF-1.4 certificato agonistico di Luca Rossi')
        ->and($response->headers->get('Content-Disposition'))->toContain('certificato Luca.pdf')
        ->and(DB::table('audit.log')->where('action', 'health.read')->where('details->fields', json_encode(['file']))->count())->toBe(1);
});

it('mostra le scadenze senza aprire i dati sanitari', function (): void {
    $s = app(CertificateService::class);
    $s->add($this->mamma->id, ['kind' => 'non_agonistic', 'issued_on' => now()->subYear()->toDateString(), 'expires_on' => now()->addDays(10)->toDateString()]);
    $before = DB::table('audit.log')->where('action', 'health.read')->count();

    $rows = collect($s->overview())->keyBy('name');

    expect($rows['Rossi Anna']['status'])->toBe('expiring')
        ->and($rows['Rossi Luca']['status'])->toBe('none')
        ->and(collect($s->overview('expiring'))->pluck('name')->all())->toBe(['Rossi Anna'])
        ->and(DB::table('audit.log')->where('action', 'health.read')->count())->toBe($before);
});

it('rifiuta file che non sono PDF o foto', function (): void {
    $exe = UploadedFile::fake()->createWithContent('virus.exe', "MZ\x90\x00");

    expect(fn () => app(CertificateService::class)->add($this->minor->id, ['kind' => 'other', 'issued_on' => now()->toDateString(), 'expires_on' => now()->addYear()->toDateString()], $exe))
        ->toThrow(PeopleError::class, 'PDF');
});

it('non fa vedere i certificati al commercialista', function (): void {
    $this->orgContext()->leave();
    $cert = $this->orgContext()->run($this->org->id, fn () => app(CertificateService::class)->add($this->minor->id, ['kind' => 'other', 'issued_on' => now()->toDateString(), 'expires_on' => now()->addYear()->toDateString()], UploadedFile::fake()->createWithContent('c.pdf', '%PDF')));
    $accountant = $this->createUser('commercialista@example.com', twoFactor: true);
    $this->addOperator($this->org, $accountant, OrgRole::Accountant);

    $this->actingAs($accountant);
    $this->get('/gestionale/certificati')->assertForbidden();
    $this->get("/gestionale/certificati/{$cert->id}/file")->assertForbidden();
    $this->get("/gestionale/soci/{$this->minor->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('person.certificates', null));

    $operator = $this->createUser('segreteria@example.com', twoFactor: true);
    $this->addOperator($this->org, $operator, OrgRole::Operator);
    $this->actingAs($operator)->get("/gestionale/soci/{$this->minor->id}")->assertInertia(fn (Assert $page) => $page->where('person.certificates.status', 'valid'));
    $this->get('/gestionale/certificati')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Soci/Certificati'));
    $this->get("/gestionale/certificati/{$cert->id}/file")->assertOk();
});

it('registra un certificato con il file dal gestionale', function (): void {
    $this->orgContext()->leave();
    $operator = $this->createUser('segreteria@example.com', twoFactor: true);
    $this->addOperator($this->org, $operator, OrgRole::Operator);

    $this->actingAs($operator)->post("/gestionale/soci/{$this->minor->id}/certificati", [
        'kind' => 'non_agonistic', 'issued_on' => now()->toDateString(), 'expires_on' => now()->addYear()->toDateString(),
        'file' => UploadedFile::fake()->createWithContent('c.pdf', '%PDF-1.4 x'),
    ])->assertSessionHas('status', 'Certificato registrato.');

    expect($this->orgContext()->run($this->org->id, fn () => MedicalCertificate::query()->count()))->toBe(1);
});
