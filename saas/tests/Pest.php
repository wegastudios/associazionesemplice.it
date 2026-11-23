<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
function downloadFullExport(Authenticatable $user, array $form = []): BinaryFileResponse
{
    $test = test();
    $test->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/gestionale/esportazione.zip', $form)->assertRedirect('/gestionale/esportazione');
    $backup = [];
    $test->get('/gestionale/esportazione')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$backup): void {
        $backup = $page->toArray()['props']['backup'] ?? [];
    });
    expect($backup['status'] ?? null)->toBe('ready')->and($backup['url'] ?? null)->toBeString();
    $response = $test->get($backup['url'])->assertOk()->assertHeader('content-type', 'application/zip')->baseResponse;
    expect($response)->toBeInstanceOf(BinaryFileResponse::class);

    return $response;
}

/**
 * I file di una copia di sicurezza: nome nell'archivio => contenuto.
 *
 * @return array<string, string>
 */
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
