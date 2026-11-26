<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/PortalHelpers.php';

// saas:portal:usage scrive quante associazioni ha inviato e, se il portale lo dice, quante ha salvato e quante non ha
// riconosciuto: solo conteggi, mai i numeri dei soci. Con associazioni non riconosciute, anche un avviso nel log.

beforeEach(function (): void {
    fakePortal();
});

it('scrive inviate, salvate e non riconosciute', function (): void {
    Http::fake(['https://portale.example.test/api/saas/v1/usage' => Http::response(['ok' => true, 'saved' => 0, 'unknown' => 0])]);
    Log::spy();

    $this->artisan('saas:portal:usage')->assertSuccessful()->expectsOutputToContain('inviate 0, salvate 0, non riconosciute 0');
    // Solo l'avviso dei consumi: altri avvisi (per esempio una query lenta) non c'entrano.
    Log::shouldNotHaveReceived('warning', ['Consumi: il portale non ha riconosciuto alcune associazioni', Mockery::any()]);
});

it('con associazioni non riconosciute dal portale avvisa nel log, senza numeri dei soci', function (): void {
    Http::fake(['https://portale.example.test/api/saas/v1/usage' => Http::response(['ok' => true, 'saved' => 0, 'unknown' => 2])]);
    Log::spy();

    $this->artisan('saas:portal:usage')->assertSuccessful()->expectsOutputToContain('non riconosciute 2');
    Log::shouldHaveReceived('warning')->once()->with('Consumi: il portale non ha riconosciuto alcune associazioni', ['sent' => 0, 'saved' => 0, 'unknown' => 2, 'unknown_refs' => []]);
});

it('scrive i ref non riconosciuti se il portale li manda, solo quelli con il formato dei ref', function (): void {
    Http::fake(['https://portale.example.test/api/saas/v1/usage' => Http::response(['ok' => true, 'saved' => 0, 'unknown' => 3,
        'unknown_refs' => ['sub_123', 'abc-9', '<script>', 42]])]);
    Log::spy();

    $this->artisan('saas:portal:usage')->assertSuccessful()->expectsOutputToContain('non riconosciute 3 (ref: sub_123, abc-9)');
    Log::shouldHaveReceived('warning')->once()->with('Consumi: il portale non ha riconosciuto alcune associazioni', ['sent' => 0, 'saved' => 0, 'unknown' => 3, 'unknown_refs' => ['sub_123', 'abc-9']]);
});

it('se il portale non dice i conteggi scrive un punto di domanda', function (): void {
    Http::fake(['https://portale.example.test/api/saas/v1/usage' => Http::response([])]);

    $this->artisan('saas:portal:usage')->assertSuccessful()->expectsOutputToContain('salvate ?, non riconosciute ?');
});
