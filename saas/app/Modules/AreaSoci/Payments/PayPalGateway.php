<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Payments;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * PayPal Orders v2 (dal PayPalApi del plugin): il socio approva su PayPal, poi l'ordine si «cattura» dal server.
 *
 * @phpstan-import-type Line from StripeGateway
 */
final readonly class PayPalGateway
{
    public function __construct(private Http $http) {}

    public static function base(bool $live): string
    {
        return $live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    public function token(bool $live, string $clientId, string $secret): string
    {
        $res = $this->http->withBasicAuth($clientId, $secret)->asForm()->acceptJson()->timeout(15)
            ->post(self::base($live).'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        $token = $res->json('access_token');
        if (! $res->successful() || ! is_string($token) || $token === '') {
            throw self::fail($res, 'accesso');
        }

        return $token;
    }

    /**
     * @param  list<Line>  $lines
     * @return array<string, mixed>
     */
    public static function orderPayload(array $lines, string $paymentId, string $returnUrl, string $cancelUrl, string $brand): array
    {
        $items = [];
        $total = 0;
        foreach ($lines as $l) {
            $total += $l['amount_cents'];
            $items[] = ['name' => mb_substr($l['label'], 0, 127), 'quantity' => '1', 'unit_amount' => ['currency_code' => 'EUR', 'value' => self::decimal($l['amount_cents'])]];
        }
        $value = self::decimal($total);

        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $paymentId, 'custom_id' => $paymentId, 'description' => mb_substr($brand, 0, 127),
                'amount' => ['currency_code' => 'EUR', 'value' => $value, 'breakdown' => ['item_total' => ['currency_code' => 'EUR', 'value' => $value]]],
                'items' => $items,
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => mb_substr($brand !== '' ? $brand : 'Associazione', 0, 120), 'locale' => 'it-IT', 'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl, 'cancel_url' => $cancelUrl,
            ]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, url: string}
     */
    public function createOrder(bool $live, string $token, array $payload, string $requestId): array
    {
        $res = $this->http->withToken($token)->withHeaders(['PayPal-Request-Id' => $requestId, 'Prefer' => 'return=representation'])->acceptJson()->timeout(15)
            ->post(self::base($live).'/v2/checkout/orders', $payload);
        $id = $res->json('id');
        if (! in_array($res->status(), [200, 201], true) || ! is_string($id)) {
            throw self::fail($res, 'ordine');
        }
        $links = $res->json('links');
        foreach (is_array($links) ? $links : [] as $l) {
            if (is_array($l) && in_array($l['rel'] ?? '', ['payer-action', 'approve'], true) && is_string($l['href'] ?? null)) {
                return ['id' => $id, 'url' => $l['href']];
            }
        }
        throw new RuntimeException('PayPal: risposta senza indirizzo di approvazione.');
    }

    /** @return array<string, mixed> l'ordine catturato (o già catturato, riletto) */
    public function capture(bool $live, string $token, string $orderId): array
    {
        $res = $this->http->withToken($token)->withHeaders(['PayPal-Request-Id' => 'capture-'.$orderId])->acceptJson()->timeout(15)
            ->withBody('{}', 'application/json')->post(self::base($live).'/v2/checkout/orders/'.rawurlencode($orderId).'/capture');
        $data = $res->json();
        if (in_array($res->status(), [200, 201], true) && is_array($data)) {
            /** @var array<string, mixed> $data */
            return $data;
        }
        if ($res->status() === 422 && str_contains($res->body(), 'ORDER_ALREADY_CAPTURED')) {
            $get = $this->http->withToken($token)->acceptJson()->timeout(15)->get(self::base($live).'/v2/checkout/orders/'.rawurlencode($orderId));
            $order = $get->json();
            if ($get->successful() && is_array($order)) {
                /** @var array<string, mixed> $order */
                return $order;
            }
        }
        throw self::fail($res, 'cattura');
    }

    /** @param  array<string, mixed>  $order */
    public static function capturedCents(array $order): int
    {
        $sum = 0;
        foreach (is_array($order['purchase_units'] ?? null) ? $order['purchase_units'] : [] as $u) {
            $captures = is_array($u) && is_array($u['payments'] ?? null) && is_array($u['payments']['captures'] ?? null) ? $u['payments']['captures'] : [];
            foreach ($captures as $c) {
                $amount = is_array($c) && is_array($c['amount'] ?? null) ? $c['amount'] : [];
                if (is_array($c) && ($c['status'] ?? '') === 'COMPLETED' && strtoupper(is_string($amount['currency_code'] ?? null) ? $amount['currency_code'] : 'EUR') === 'EUR') {
                    $sum += (int) round((float) (is_string($amount['value'] ?? null) ? $amount['value'] : '0') * 100);
                }
            }
        }

        return $sum;
    }

    /** @param  array<string, mixed>  $order */
    public static function isCompleted(array $order): bool
    {
        return ($order['status'] ?? '') === 'COMPLETED' && self::capturedCents($order) > 0;
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.sprintf('%02d', $cents % 100);
    }

    private static function fail(Response $res, string $what): RuntimeException
    {
        $msg = $res->json('message') ?? $res->json('error_description');

        return new RuntimeException('PayPal ('.$what.'): '.(is_string($msg) && $msg !== '' ? $msg : 'risposta '.$res->status()));
    }
}
