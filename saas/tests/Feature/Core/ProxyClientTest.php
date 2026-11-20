<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

const PROXY_SECRET = 'segreto-di-prova-condiviso-0123456789abcdef';

beforeEach(function (): void {
    Route::middleware('web')->get('/_prova/indirizzo', fn () => response()->json(['ip' => request()->ip(), 'host' => request()->getHost()]));
});

it('legge l\'indirizzo del visitatore da X-Forwarded-For solo con il segreto giusto', function (): void {
    config(['saas.proxy_secret' => PROXY_SECRET]);
    $server = ['REMOTE_ADDR' => '10.0.0.5'];

    $this->withServerVariables($server)->get('/_prova/indirizzo', ['X-Forwarded-For' => '203.0.113.9', 'X-Saas-Proxy-Secret' => PROXY_SECRET])
        ->assertJson(['ip' => '203.0.113.9']);
    $this->withServerVariables($server)->get('/_prova/indirizzo', ['X-Forwarded-For' => '203.0.113.9', 'X-Saas-Proxy-Secret' => 'sbagliato'])
        ->assertJson(['ip' => '10.0.0.5']);
    $this->withServerVariables($server)->get('/_prova/indirizzo', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertJson(['ip' => '10.0.0.5']);
});

it('ignora un indirizzo inoltrato che non è un indirizzo, anche con il segreto', function (): void {
    config(['saas.proxy_secret' => PROXY_SECRET]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('/_prova/indirizzo', ['X-Forwarded-For' => 'non-un-indirizzo', 'X-Saas-Proxy-Secret' => PROXY_SECRET])
        ->assertJson(['ip' => '10.0.0.5']);
});

it('non vale un segreto troppo corto', function (): void {
    config(['saas.proxy_secret' => 'corto']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('/_prova/indirizzo', ['X-Forwarded-For' => '203.0.113.9', 'X-Saas-Proxy-Secret' => 'corto'])
        ->assertJson(['ip' => '10.0.0.5']);
});

it('non si fida mai dell\'host inoltrato', function (): void {
    config(['saas.proxy_secret' => PROXY_SECRET]);

    $this->get('/_prova/indirizzo', ['X-Forwarded-Host' => 'attaccante.example', 'X-Saas-Proxy-Secret' => PROXY_SECRET])
        ->assertJson(['host' => 'localhost']);
});
