<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Payments\PayPalGateway;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// PayPal simulato, credenziali finte (H14): gettone in cache cifrato, rinnovato dopo un 401.

it('tiene il gettone di PayPal in cache, cifrato, e ne chiede uno nuovo solo se PayPal lo rifiuta', function (): void {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::sequence()
            ->push(['access_token' => 'gettone-uno', 'expires_in' => 32400])
            ->push(['access_token' => 'gettone-due', 'expires_in' => 32400])
            ->push(['access_token' => 'gettone-tre', 'expires_in' => 32400]),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::sequence()
            ->push(['id' => 'ORD1', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/1']]], 201)
            ->push(['id' => 'ORD2', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/2']]], 201)
            ->push(['name' => 'AUTHENTICATION_FAILURE'], 401)
            ->push(['id' => 'ORD3', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/3']]], 201),
    ]);
    $paypal = app(PayPalGateway::class);
    $tokens = static fn (): int => Http::recorded(static fn (HttpRequest $r): bool => str_ends_with($r->url(), '/v1/oauth2/token'))->count();

    expect($paypal->createOrder(false, 'client-finto', 'segreto-finto', [], 'p1')['id'])->toBe('ORD1')
        ->and($paypal->createOrder(false, 'client-finto', 'segreto-finto', [], 'p2')['id'])->toBe('ORD2')
        ->and($tokens())->toBe(1);
    $key = $paypal->tokenKey(false, 'client-finto', 'segreto-finto');
    expect(Cache::get($key))->toBeString()->not->toContain('gettone-uno')
        ->and($key)->not->toContain(hash('sha256', "sandbox\0client-finto\0segreto-finto"));

    // Gettone rifiutato: si butta, se ne chiede uno nuovo e la chiamata si ripete una volta.
    expect($paypal->createOrder(false, 'client-finto', 'segreto-finto', [], 'p3')['id'])->toBe('ORD3')
        ->and($tokens())->toBe(2);
    $sent = Http::recorded(static fn (HttpRequest $r): bool => str_ends_with($r->url(), '/v2/checkout/orders'))
        ->map(static fn (array $pair): string => $pair[0]->header('Authorization')[0] ?? '')->values()->all();
    expect($sent)->toBe(['Bearer gettone-uno', 'Bearer gettone-uno', 'Bearer gettone-uno', 'Bearer gettone-due']);

    // La prova delle credenziali nelle impostazioni chiede sempre un gettone nuovo.
    expect($paypal->token(false, 'client-finto', 'segreto-finto'))->toBe('gettone-tre')->and($tokens())->toBe(3);
});

it('cattura e rimborso mandano a PayPal lo stesso PayPal-Request-Id anche quando la chiamata si ripete', function (): void {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'gettone', 'expires_in' => 32400]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORD9/capture' => Http::sequence()
            ->push(['name' => 'AUTHENTICATION_FAILURE'], 401)
            ->push(['id' => 'ORD9', 'status' => 'COMPLETED'], 201),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORD9' => Http::response(['purchase_units' => [['payments' => ['captures' => [['id' => 'CAP9', 'status' => 'COMPLETED']]]]]]),
        'api-m.sandbox.paypal.com/v2/payments/captures/CAP9/refund' => Http::sequence()
            ->push(['name' => 'AUTHENTICATION_FAILURE'], 401)
            ->push(['id' => 'RF9'], 201),
    ]);
    $paypal = app(PayPalGateway::class);
    $ids = static fn (string $path): array => Http::recorded(static fn (HttpRequest $r): bool => str_ends_with($r->url(), $path))
        ->map(static fn (array $pair): string => $pair[0]->header('PayPal-Request-Id')[0] ?? '')->values()->all();

    expect($paypal->capture(false, 'client-finto', 'segreto-finto', 'ORD9', 'capture-pagamento-1')['status'])->toBe('COMPLETED')
        ->and($paypal->refund(false, 'client-finto', 'segreto-finto', 'ORD9', 1250, 'refund-pagamento-1-0-1250'))->toBe('RF9')
        ->and($ids('/capture'))->toBe(['capture-pagamento-1', 'capture-pagamento-1'])
        ->and($ids('/refund'))->toBe(['refund-pagamento-1-0-1250', 'refund-pagamento-1-0-1250']);
});
