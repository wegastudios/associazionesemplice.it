<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Inertia\Testing\AssertableInertia;
use Tests\FeatureTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Architecture');

pest()->extend(FeatureTestCase::class)->in('Feature');

/**
 * Copia di sicurezza completa come dalla pagina: la chiede (in coda; nei test la coda gira subito) e la scarica dal
 * collegamento firmato che la pagina mostra quando è pronta.
 *
 * @param  array<string, mixed>  $form
 */
function downloadFullExport(Authenticatable $user, array $form = []): string
{
    $test = test();
    $test->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/gestionale/esportazione.zip', $form)->assertRedirect('/gestionale/esportazione');
    $backup = [];
    $test->get('/gestionale/esportazione')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$backup): void {
        $backup = $page->toArray()['props']['backup'] ?? [];
    });
    expect($backup['status'] ?? null)->toBe('ready')->and($backup['url'] ?? null)->toBeString();

    // Il file è cifrato su disco: PHP lo decifra mentre lo manda.
    return $test->get($backup['url'])->assertOk()->assertHeader('content-type', 'application/zip')->streamedContent();
}

/**
 * I file di una copia di sicurezza: nome nell'archivio => contenuto.
 *
 * @return array<string, string>
 */
function zipContents(string $bytes): array
{
    $path = storage_path('app/tmp/prova-'.bin2hex(random_bytes(8)).'.zip');
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $out[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($path);

    return $out;
}
