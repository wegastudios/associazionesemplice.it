<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\OrgBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/*
 * I comandi che girano su tutte le associazioni fanno la pausa tra una e l'altra (SAAS_KEY_BATCH_PAUSE_MS).
 */

beforeEach(function (): void {
    $this->createOrg('Polisportiva Alfa');
    $this->createOrg('Polisportiva Beta');
    $this->createOrg('Polisportiva Gamma');
    config(['saas.crypto.batch_pause_ms' => 500]);
    $this->app->forgetInstance(OrgBatch::class);
    Sleep::fake();
});

afterEach(function (): void {
    Sleep::fake(false);
});

it('fa una pausa tra un\'associazione e l\'altra', function (string $command): void {
    $orgs = DB::table('core.orgs')->whereNull('deleted_at')->count();
    expect($orgs)->toBeGreaterThanOrEqual(3);

    $this->artisan($command)->run();

    Sleep::assertSleptTimes($orgs - 1);
    Sleep::assertSequence(array_map(static fn (): Sleep => Sleep::for(500)->milliseconds(), range(1, $orgs - 1)));
})->with([
    'verifica del registro' => 'saas:audit:verify',
    'verifica degli archivi' => 'saas:archive:verify',
]);

it('il giro non resta acceso dopo il comando', function (): void {
    $this->artisan('saas:audit:verify')->run();

    expect(app(OrgBatch::class)->active())->toBeFalse();
});
