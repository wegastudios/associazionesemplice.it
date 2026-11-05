<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Payments;

use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/**
 * Stripe Checkout (dallo StripeApi del plugin): il socio paga su una pagina di Stripe, noi non vediamo mai la carta.
 *
 * @phpstan-type Line array{label: string, amount_cents: int}
 */
final readonly class StripeGateway
{
    public const BASE = 'https://api.stripe.com';

    public function __construct(private Http $http) {}

    /**
     * Parametri della sessione di pagamento.
     *
     * @param  list<Line>  $lines
     * @return array<string, mixed>
     */
    public static function checkoutParams(array $lines, string $paymentId, ?string $email, string $successUrl, string $cancelUrl): array
    {
        $items = [];
        foreach ($lines as $i => $l) {
            $items[$i] = ['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => $l['amount_cents'], 'product_data' => ['name' => mb_substr($l['label'], 0, 250)]]];
        }
        $params = [
            'mode' => 'payment', 'locale' => 'it', 'success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'client_reference_id' => $paymentId,
            'line_items' => $items, 'metadata' => ['as_payment' => $paymentId], 'payment_intent_data' => ['metadata' => ['as_payment' => $paymentId]],
        ];
        if ($email !== null && $email !== '') {
            $params['customer_email'] = $email;
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{id: string, url: string}
     */
    public function createSession(string $secret, array $params, string $idempotencyKey): array
    {
        $d = $this->call('POST', '/v1/checkout/sessions', $secret, $params, ['Idempotency-Key' => $idempotencyKey]);
        $id = $d['id'] ?? null;
        $url = $d['url'] ?? null;
        if (! is_string($id) || ! is_string($url) || $url === '') {
            throw new RuntimeException('Stripe: risposta senza indirizzo di pagamento.');
        }

        return ['id' => $id, 'url' => $url];
    }

    /** @return array<string, mixed> */
    public function retrieveSession(string $secret, string $sessionId): array
    {
        return $this->call('GET', '/v1/checkout/sessions/'.rawurlencode($sessionId), $secret);
    }

    /** Prova delle chiavi: legge il saldo del conto. */
    public function test(string $secret): void
    {
        $this->call('GET', '/v1/balance', $secret);
    }

    /** @param  array<string, mixed>  $session */
    public static function isPaid(array $session): bool
    {
        return ($session['payment_status'] ?? '') === 'paid';
    }

    /**
     * Incassato in centesimi di euro (un'altra valuta vale 0: si controlla a mano).
     *
     * @param  array<string, mixed>  $session
     */
    public static function paidCents(array $session): int
    {
        if (is_string($session['currency'] ?? null) && strtolower($session['currency']) !== 'eur') {
            return 0;
        }

        return is_int($session['amount_total'] ?? null) ? max(0, $session['amount_total']) : 0;
    }

    /**
     * @param  array<string, mixed>|null  $form
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, string $secret, ?array $form = null, array $headers = []): array
    {
        $req = $this->http->withToken($secret)->withHeaders($headers)->timeout(15)->acceptJson();
        $res = $form === null ? $req->send($method, self::BASE.$path) : $req->asForm()->send($method, self::BASE.$path, ['form_params' => $form]);
        $data = $res->json();
        if (! $res->successful() || ! is_array($data)) {
            $msg = is_array($data) && is_array($data['error'] ?? null) && is_string($data['error']['message'] ?? null) ? $data['error']['message'] : 'risposta '.$res->status();
            throw new RuntimeException('Stripe: '.$msg);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
