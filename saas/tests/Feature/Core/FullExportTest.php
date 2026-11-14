<?php

declare(strict_types=1);

use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\CertificateService;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($p->id, Account::query()->sole()->id, 3000);
        app(CertificateService::class)->add($p->id, ['kind' => 'agonistic', 'issued_on' => now()->subMonth()->toDateString(), 'expires_on' => now()->addYear()->toDateString(), 'doctor' => 'Dr. Bianchi']);
    });
});

/** @return array<string, string> */
function zipContents(BinaryFileResponse $response): array
{
    $zip = new ZipArchive;
    $zip->open($response->getFile()->getPathname());
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $out[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();

    return $out;
}

it('esporta tutti i dati in un archivio per il titolare', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);

    $this->orgContext()->run($this->org->id, fn () => app(FiscalYearService::class)->close((int) now()->format('Y')));
    $response = $this->actingAs($owner)->get('/gestionale/esportazione.zip')->assertOk()->assertHeader('content-type', 'application/zip');
    $base = $response->baseResponse;
    expect($base)->toBeInstanceOf(BinaryFileResponse::class);
    $files = zipContents($base);

    expect(array_keys($files))->toContain(
        'LEGGIMI.txt', 'soci/anagrafica.csv', 'soci/libro-soci.csv', 'soci/tessere.csv', 'soci/certificati-medici.csv', 'soci/consensi.csv',
        'contabilita/prima-nota.csv', 'contabilita/conti.csv', 'contabilita/ricevute.csv', 'attivita/attivita.csv',
        'operatori.csv', 'ruoli.csv', 'impostazioni.csv', 'registro-delle-azioni.csv',
    )
        ->and(array_keys($files))->toContain('contabilita/rendiconti-esercizi-chiusi.json', 'soci/libri-soci-esercizi-chiusi.json')
        ->and(json_decode($files['soci/libri-soci-esercizi-chiusi.json'], true)[0]['rows'][0]['name'])->toBe('Rossi Mario')
        ->and($files['soci/anagrafica.csv'])->toContain('RSSMRA80A01H501U')
        ->and($files['soci/certificati-medici.csv'])->toContain('Dr. Bianchi')
        ->and($files['contabilita/prima-nota.csv'])->toContain('Quota associativa')
        ->and($files['operatori.csv'])->toContain('own@example.com')
        ->and($files['LEGGIMI.txt'])->toContain('Polisportiva Alfa');
    $this->orgContext()->run($this->org->id, fn () => expect(DB::table('audit.log')->where('action', 'export.full')->exists())->toBeTrue());
});

it('non lascia l\'esportazione completa a segreteria e commercialista', function (): void {
    foreach ([OrgRole::Operator, OrgRole::Accountant] as $role) {
        $user = $this->createUser($role->value.'@example.com', twoFactor: true);
        $this->addOperator($this->org, $user, $role);
        $this->actingAs($user)->get('/gestionale/esportazione.zip')->assertForbidden();
    }
});
