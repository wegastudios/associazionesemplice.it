<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Payments;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * PayPal Orders v2 (dal PayPalApi del plugin): il socio approva su PayPal, poi l'ordine si «cattura» dal server.
 *
 * Il gettone d'accesso si tiene in cache, cifrato, fino a poco prima della scadenza (una chiave per ambiente e
 * credenziali, quindi per associazione): un 401 lo butta e la chiamata si ripete una volta con uno nuovo (H14).
 * Connessione al massimo CONNECT_SECONDS, risposta al massimo 15 secondi.
 *
 * @phpstan-import-type Line from StripeGateway
 */
final readonly class PayPalGateway
{
    public const CONNECT_SECONDS = 3;

    /** Secondi prima della scadenza in cui il gettone in cache non si usa più. */
    private const TOKEN_MARGIN = 300;

    public function __construct(private Http $http, private Cache $cache, private Encrypter $crypt) {}

    public static function base(bool $live): string
    {
        return $live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    /** Un gettone nuovo, senza cache: per provare le credenziali appena inserite. */
    public function token(bool $live, string $clientId, string $secret): string
    {
        return $this->issue($live, $clientId, $secret)[0];
    }

    public static function tokenKey(bool $live, string $clientId, string $secret): string
    {
        return 'paypal-token|'.hash('sha256', ($live ? 'live' : 'sandbox')."\0".$clientId."\0".$secret);
    }

    /** @return array{0: string, 1: int} gettone e secondi di validità (0 = non detti) */
    private function issue(bool $live, string $clientId, string $secret): array
    {
        $res = $this->http->withBasicAuth($clientId, $secret)->asForm()->acceptJson()->connectTimeout(self::CONNECT_SECONDS)->timeout(15)
            ->post(self::base($live).'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        $token = $res->json('access_token');
        if (! $res->successful() || ! is_string($token) || $token === '') {
            throw self::fail($res, 'accesso');
        }
        $seconds = $res->json('expires_in');

        return [$token, is_int($seconds) ? $seconds : 0];
    }

    private function cachedToken(bool $live, string $clientId, string $secret): string
    {
        $key = self::tokenKey($live, $clientId, $secret);
        $stored = $this->cache->get($key);
        if (is_string($stored)) {
            try {
                $token = $this->crypt->decryptString($stored);
                if ($token !== '') {
                    return $token;
                }
            } catch (DecryptException) {
                // chiave dell'applicazione cambiata: se ne chiede uno nuovo
            }
        }
        [$token, $seconds] = $this->issue($live, $clientId, $secret);
        if ($seconds > self::TOKEN_MARGIN + 60) {
            $this->cache->put($key, $this->crypt->encryptString($token), $seconds - self::TOKEN_MARGIN);
        }

        return $token;
    }

    /**
     * Una chiamata con il gettone in cache; se PayPal lo rifiuta (401) si butta e si ripete una volta con uno nuovo.
     *
     * @param  Closure(PendingRequest): Response  $request
     */
    private function call(bool $live, string $clientId, string $secret, Closure $request): Response
    {
        $res = $request($this->client()->withToken($this->cachedToken($live, $clientId, $secret)));
        if ($res->status() === 401) {
            $this->cache->forget(self::tokenKey($live, $clientId, $secret));
            $res = $request($this->client()->withToken($this->cachedToken($live, $clientId, $secret)));
        }

        return $res;
    }

    private function client(): PendingRequest
    {
        return $this->http->acceptJson()->connectTimeout(self::CONNECT_SECONDS)->timeout(15);
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
            $item = ['name' => mb_substr($l['label'], 0, 127), 'quantity' => '1', 'unit_amount' => ['currency_code' => 'EUR', 'value' => self::decimal($l['amount_cents'])]];
            if (($l['donation'] ?? false) === true) {
                $item['category'] = 'DONATION';
            }
            $items[] = $item;
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
    public function createOrder(bool $live, string $clientId, string $secret, array $payload, string $requestId): array
    {
        $res = $this->call($live, $clientId, $secret, static fn (PendingRequest $http): Response => $http->withHeaders(['PayPal-Request-Id' => $requestId, 'Prefer' => 'return=representation'])
            ->post(self::base($live).'/v2/checkout/orders', $payload));
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
    public function capture(bool $live, string $clientId, string $secret, string $orderId): array
    {
        $res = $this->call($live, $clientId, $secret, static fn (PendingRequest $http): Response => $http->withHeaders(['PayPal-Request-Id' => 'capture-'.$orderId])
            ->withBody('{}', 'application/json')->post(self::base($live).'/v2/checkout/orders/'.rawurlencode($orderId).'/capture'));
        $data = $res->json();
        if (in_array($res->status(), [200, 201], true) && is_array($data)) {
            /** @var array<string, mixed> $data */
            return $data;
        }
        if ($res->status() === 422 && str_contains($res->body(), 'ORDER_ALREADY_CAPTURED')) {
            $get = $this->call($live, $clientId, $secret, static fn (PendingRequest $http): Response => $http->get(self::base($live).'/v2/checkout/orders/'.rawurlencode($orderId)));
            $order = $get->json();
            if ($get->successful() && is_array($order)) {
                /** @var array<string, mixed> $order */
                return $order;
            }
        }
        throw self::fail($res, 'cattura');
    }

    /** Restituisce al socio parte (o tutto) dell'incasso dell'ordine: l'identificativo del rimborso. */
    public function refund(bool $live, string $clientId, string $secret, string $orderId, int $cents, string $requestId): string
    {
        $get = $this->call($live, $clientId, $secret, static fn (PendingRequest $http): Response => $http->get(self::base($live).'/v2/checkout/orders/'.rawurlencode($orderId)));
        $order = $get->json();
        /** @var array<string, mixed>|null $order */
        $capture = $get->successful() && is_array($order) ? self::captureId($order) : null;
        if ($capture === null) {
            throw new RuntimeException('PayPal: incasso non trovato, niente da rimborsare.');
        }
        $res = $this->call($live, $clientId, $secret, static fn (PendingRequest $http): Response => $http->withHeaders(['PayPal-Request-Id' => $requestId])
            ->post(self::base($live).'/v2/payments/captures/'.rawurlencode($capture).'/refund', ['amount' => ['value' => self::decimal($cents), 'currency_code' => 'EUR']]));
        if (in_array($res->json('status'), ['FAILED', 'CANCELLED'], true)) {
            throw new RuntimeException('PayPal: il rimborso non è andato a buon fine.');
        }
        $id = $res->json('id');
        if (! in_array($res->status(), [200, 201], true) || ! is_string($id)) {
            throw self::fail($res, 'rimborso');
        }

        return $id;
    }

    /** @param  array<string, mixed>  $order */
    public static function captureId(array $order): ?string
    {
        foreach (is_array($order['purchase_units'] ?? null) ? $order['purchase_units'] : [] as $u) {
            $captures = is_array($u) && is_array($u['payments'] ?? null) && is_array($u['payments']['captures'] ?? null) ? $u['payments']['captures'] : [];
            foreach ($captures as $c) {
                if (is_array($c) && ($c['status'] ?? '') === 'COMPLETED' && is_string($c['id'] ?? null)) {
                    return $c['id'];
                }
            }
        }

        return null;
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

        $text = 'PayPal ('.$what.'): '.(is_string($msg) && $msg !== '' ? $msg : 'risposta '.$res->status());

        return GatewayUnknown::applies($res) ? new GatewayUnknown($text) : new RuntimeException($text);
    }
}
