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

    /**
     * Restituisce al socio parte (o tutto) di un pagamento: l'identificativo del rimborso.
     * Con una chiave limitata serve il permesso di scrittura sui rimborsi.
     */
    public function refund(string $secret, string $sessionId, int $cents, string $idempotencyKey): string
    {
        $session = $this->retrieveSession($secret, $sessionId);
        $intent = $session['payment_intent'] ?? null;
        if (! is_string($intent) || $intent === '') {
            throw new RuntimeException('Stripe: pagamento non trovato, niente da rimborsare.');
        }
        $d = $this->call('POST', '/v1/refunds', $secret, ['payment_intent' => $intent, 'amount' => $cents], ['Idempotency-Key' => $idempotencyKey]);
        if (in_array($d['status'] ?? null, ['failed', 'canceled'], true)) {
            throw new RuntimeException('Stripe: il rimborso non è andato a buon fine.');
        }
        $id = $d['id'] ?? null;
        if (! is_string($id)) {
            throw new RuntimeException('Stripe: rimborso senza conferma.');
        }

        return $id;
    }

    /** Prova delle chiavi: legge il saldo del conto. */
    public function test(string $secret): void
    {
        $this->call('GET', '/v1/balance', $secret);
    }

    /**
     * Crea il webhook che avvisa del pagamento arrivato e restituisce il suo segreto di firma. Se sull'indirizzo c'è già un
     * webhook non si tocca (il segreto di uno esistente Stripe non lo restituisce più): restituisce null.
     * Con una chiave limitata serve il permesso di scrittura sui webhook.
     *
     * @return array{id: string, secret: string}|null
     */
    public function createWebhook(string $secret, string $url): ?array
    {
        $existing = $this->call('GET', '/v1/webhook_endpoints?limit=100', $secret);
        foreach (is_array($existing['data'] ?? null) ? $existing['data'] : [] as $endpoint) {
            if (is_array($endpoint) && ($endpoint['url'] ?? null) === $url) {
                return null;
            }
        }
        $d = $this->call('POST', '/v1/webhook_endpoints', $secret, ['url' => $url, 'enabled_events' => ['checkout.session.completed'], 'description' => 'Associazione Semplice: pagamenti dei soci']);
        $id = $d['id'] ?? null;
        $signing = $d['secret'] ?? null;
        if (! is_string($id) || ! is_string($signing) || preg_match('/^whsec_[A-Za-z0-9]+$/', $signing) !== 1) {
            throw new RuntimeException('Stripe: webhook creato senza segreto di firma.');
        }

        return ['id' => $id, 'secret' => $signing];
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
            throw GatewayUnknown::applies($res) ? new GatewayUnknown('Stripe: '.$msg) : new RuntimeException('Stripe: '.$msg);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
