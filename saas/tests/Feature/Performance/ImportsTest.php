<?php

declare(strict_types=1);

use App\Modules\Contabilita\Import\LedgerImportService;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\PeopleImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Import soci e prima nota (H10, H11): query per riga, tempo, e la richiesta che non lavora più le righe. Dati inventati.

/**
 * Query e millisecondi di $work.
 *
 * @return array{0: int, 1: int}
 */
function importMeasure(Closure $work): array
{
    $n = 0;
    DB::listen(static function () use (&$n): void {
        $n++;
    });
    $start = hrtime(true);
    $work();

    return [$n, (int) round((hrtime(true) - $start) / 1e6)];
}

function importPeopleCsv(int $rows, int $offset = 0): string
{
    $csv = 'Nome;Cognome;Email;'.now()->format('Y')."\n";
    for ($i = $offset; $i < $offset + $rows; $i++) {
        $csv .= "Nome{$i};Importato{$i};imp{$i}@example.com;x\n";
    }

    return $csv;
}

function importLedgerCsv(int $rows, int $offset = 0): string
{
    $date = '10/01/'.now()->format('Y');
    $csv = "Data;Importo;Conto;Descrizione\n";
    for ($i = $offset; $i < $offset + $rows; $i++) {
        $csv .= "{$date};1".($i % 90).",00;Cassa contanti;Movimento {$i}\n";
    }

    return $csv;
}

function importTempFile(string $content): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, $content);

    return $path;
}

it('l\'import soci non fa query per ogni riga (H10)', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $run = fn (int $rows, int $offset): array => $this->orgContext()->run($org->id, function () use ($rows, $offset): array {
        $s = app(PeopleImportService::class);
        $plan = $s->preview(importTempFile(importPeopleCsv($rows, $offset)), 'soci.csv', MemberType::Ordinary)['plan'] ?? [];

        return importMeasure(static fn () => expect($s->apply($plan, false)['created'])->toBe($rows));
    });

    [$fewQ, $fewMs] = $run(20, 0);
    [$manyQ, $manyMs] = $run(60, 100);
    $perRow = ($manyQ - $fewQ) / 40;
    fwrite(STDERR, "\n[H10 misure] import soci con tessera: 20 righe {$fewQ} query {$fewMs} ms · 60 righe {$manyQ} query {$manyMs} ms · ".round($perRow, 2)." query in più per riga\n");

    $this->orgContext()->run($org->id, fn () => expect(Person::query()->count())->toBe(80));
    expect($perRow)->toBeLessThanOrEqual(1.5, 'import soci: troppe query per riga');
});

it('l\'import della prima nota non fa query per ogni movimento (H11)', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $run = fn (int $rows, int $offset): array => $this->orgContext()->run($org->id, function () use ($rows, $offset): array {
        $s = app(LedgerImportService::class);
        $plan = $s->preview(importTempFile(importLedgerCsv($rows, $offset)), 'pn.csv', null)['plan'] ?? [];

        return importMeasure(static fn () => expect($s->apply($plan)['income'])->toBe($rows));
    });

    [$fewQ, $fewMs] = $run(20, 0);
    [$manyQ, $manyMs] = $run(60, 100);
    $perRow = ($manyQ - $fewQ) / 40;
    fwrite(STDERR, "\n[H11 misure] import prima nota: 20 movimenti {$fewQ} query {$fewMs} ms · 60 movimenti {$manyQ} query {$manyMs} ms · ".round($perRow, 2)." query in più per movimento\n");

    expect($perRow)->toBeLessThanOrEqual(1.0, 'import prima nota: troppe query per movimento');
});

it('la richiesta di import non lavora le righe: stesse query con 20 o con 80 righe (H10, H11)', function (): void {
    Queue::fake();
    $measure = function (string $url, string $csv, array $extra): array {
        $org = $this->createOrg('Polisportiva '.Str::random(4));
        $user = $this->createUser(Str::random(8).'@example.com', twoFactor: true);
        $this->addOperator($org, $user, OrgRole::Owner);
        $this->actingAs($user);

        return importMeasure(fn () => $this->post($url, ['file' => UploadedFile::fake()->createWithContent('f.csv', $csv), 'apply' => true] + $extra)->assertSessionHasNoErrors());
    };

    [$peopleFew] = $measure('/gestionale/soci/importa', importPeopleCsv(20), ['default_type' => 'ordinary']);
    [$peopleMany] = $measure('/gestionale/soci/importa', importPeopleCsv(80), ['default_type' => 'ordinary']);
    [$ledgerFew] = $measure('/gestionale/prima-nota/importa', importLedgerCsv(20), []);
    [$ledgerMany] = $measure('/gestionale/prima-nota/importa', importLedgerCsv(80), []);
    fwrite(STDERR, "\n[H10/H11 misure] richiesta di import: soci {$peopleFew} query con 20 righe, {$peopleMany} con 80 · prima nota {$ledgerFew} con 20, {$ledgerMany} con 80\n");

    expect($peopleMany)->toBe($peopleFew, 'richiesta import soci: le query crescono con le righe')
        ->and($ledgerMany)->toBe($ledgerFew, 'richiesta import prima nota: le query crescono con le righe');
});
