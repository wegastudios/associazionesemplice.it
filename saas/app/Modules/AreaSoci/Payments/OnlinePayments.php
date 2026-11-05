<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Payments;

use App\Modules\AreaSoci\Models\PaymentSettings;
use App\Modules\AreaSoci\Services\MemberAccessError;
use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\PayableDues;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Pagamenti online dei soci (dal PaymentService del plugin). Il socio sceglie le voci dovute (ricalcolate qui), paga su
 * Stripe o PayPal con il conto dell'associazione; all'incasso confermato (ritorno dal pagamento o webhook di Stripe)
 * ogni voce si registra in prima nota con la sua ricevuta, una volta sola. Se qualcosa non torna (importo diverso, voce
 * non più dovuta) il pagamento resta «da controllare» per la segreteria.
 *
 * @phpstan-import-type Due from PayableDues
 *
 * @phpstan-type Payment array{id: string, person_id: string, provider: string, status: string, amount_cents: int, paid_cents: int|null, items: list<Due>, receipts: list<string>, provider_ref: string|null, error: string|null, created_at: string}
 */
final readonly class OnlinePayments
{
    public const MIN_CENTS = 50;

    public const MAX_PER_HOUR = 10;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private Container $app,
        private StripeGateway $stripe,
        private PayPalGateway $paypal,
        private AuditLog $audit,
    ) {}

    public function settings(): ?PaymentSettings
    {
        return PaymentSettings::query()->first();
    }

    /** @return list<string> */
    public function providers(): array
    {
        return $this->settings()?->providers() ?? [];
    }

    /**
     * Le voci dovute ora, da tutti i moduli.
     *
     * @param  list<string>  $personIds
     * @return list<Due>
     */
    public function dues(array $personIds): array
    {
        $out = [];
        foreach ($this->modules() as $m) {
            array_push($out, ...$m->dues($personIds));
        }

        return $out;
    }

    /**
     * Crea il pagamento e restituisce la pagina del servizio di pagamento a cui mandare il socio.
     *
     * @param  list<string>  $personIds  le schede che il socio può pagare (la sua e quelle dei figli)
     * @param  list<string>  $keys
     */
    public function checkout(string $payerId, array $personIds, array $keys, string $provider, ?string $email, string $baseUrl, string $brand): string
    {
        $settings = $this->settings();
        if ($settings === null || ! in_array($provider, $settings->providers(), true)) {
            throw new MemberAccessError('Questo metodo di pagamento non è attivo.');
        }
        $recent = $this->table()->where('person_id', $payerId)->where('created_at', '>', now()->subHour())->count();
        if ($recent >= self::MAX_PER_HOUR) {
            throw new MemberAccessError('Hai avviato molti pagamenti in poco tempo: aspetta un po\' prima di riprovare.');
        }
        $dues = [];
        foreach ($this->dues($personIds) as $d) {
            $dues[$d['key']] = $d;
        }
        $items = [];
        foreach (array_unique($keys) as $k) {
            if (isset($dues[$k])) {
                $items[] = $dues[$k];
            }
        }
        if ($items === []) {
            throw new MemberAccessError('Scegli almeno una voce da pagare.');
        }
        $total = (int) array_sum(array_column($items, 'amount_cents'));
        if ($total < self::MIN_CENTS) {
            throw new MemberAccessError('L\'importo minimo per pagare online è 0,50 €.');
        }

        $id = Str::uuid7()->toString();
        $this->table()->insert(['org_id' => $this->context->require()->id, 'id' => $id, 'person_id' => $payerId, 'provider' => $provider,
            'amount_cents' => $total, 'items' => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        $success = rtrim($baseUrl, '/').'/area/pagamenti/'.$id.'/esito';
        $cancel = rtrim($baseUrl, '/').'/area/pagamenti/'.$id.'/annullato';
        $lines = array_map(static fn (array $i): array => ['label' => $i['label'], 'amount_cents' => $i['amount_cents']], $items);
        try {
            if ($provider === 'stripe') {
                $res = $this->stripe->createSession((string) $settings->stripe_secret, StripeGateway::checkoutParams($lines, $id, $email, $success.'?session_id={CHECKOUT_SESSION_ID}', $cancel), $id);
            } else {
                $token = $this->paypal->token($settings->paypal_live, (string) $settings->paypal_client_id, (string) $settings->paypal_secret);
                $res = $this->paypal->createOrder($settings->paypal_live, $token, PayPalGateway::orderPayload($lines, $id, $success, $cancel, $brand), $id);
            }
        } catch (RuntimeException $e) {
            $this->table()->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
            $this->audit->record('payment.failed', 'app.online_payments', $id, ['provider' => $provider]);
            throw new MemberAccessError('Il servizio di pagamento non risponde in questo momento. Riprova più tardi.');
        }
        $this->table()->where('id', $id)->update(['status' => 'pending', 'provider_ref' => $res['id'], 'updated_at' => now()]);
        $this->audit->record('payment.created', 'app.online_payments', $id, ['provider' => $provider, 'cents' => $total, 'items' => count($items)]);

        return $res['url'];
    }

    /**
     * Il socio torna dal pagamento: si chiede al servizio se è andato a buon fine e, se sì, si registra.
     *
     * @return Payment|null
     */
    public function confirm(string $paymentId, string $personId): ?array
    {
        $p = $this->find($paymentId);
        if ($p === null || $p['person_id'] !== $personId) {
            return null;
        }
        if ($p['status'] !== 'pending' || $p['provider_ref'] === null) {
            return $p;
        }
        $settings = $this->settings();
        if ($settings === null) {
            return $p;
        }
        try {
            if ($p['provider'] === 'stripe') {
                $session = $this->stripe->retrieveSession((string) $settings->stripe_secret, $p['provider_ref']);
                if (StripeGateway::isPaid($session)) {
                    $this->settle($p['id'], StripeGateway::paidCents($session));
                }
            } else {
                $token = $this->paypal->token($settings->paypal_live, (string) $settings->paypal_client_id, (string) $settings->paypal_secret);
                $order = $this->paypal->capture($settings->paypal_live, $token, $p['provider_ref']);
                if (PayPalGateway::isCompleted($order)) {
                    $this->settle($p['id'], PayPalGateway::capturedCents($order));
                }
            }
        } catch (RuntimeException $e) {
            $this->table()->where('id', $p['id'])->update(['error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
        }

        return $this->find($paymentId);
    }

    /** Il socio ha rinunciato sulla pagina del servizio. */
    public function cancel(string $paymentId, string $personId): void
    {
        $this->table()->where('id', $paymentId)->where('person_id', $personId)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /** Webhook di Stripe: firma verificata con il segreto dell'associazione. @return bool la firma era valida */
    public function stripeWebhook(string $payload, string $signature): bool
    {
        $secret = $this->settings()?->stripe_webhook;
        if ($secret === null || ! StripeWebhook::verify($payload, $signature, $secret, time())) {
            return false;
        }
        $event = json_decode($payload, true);
        $session = is_array($event) && is_array($event['data'] ?? null) && is_array($event['data']['object'] ?? null) ? $event['data']['object'] : null;
        $type = is_array($event) ? ($event['type'] ?? '') : '';
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && $session !== null) {
            $ref = is_string($session['id'] ?? null) ? $session['id'] : '';
            $id = $this->table()->where('provider', 'stripe')->where('provider_ref', $ref)->value('id');
            /** @var array<string, mixed> $session */
            if (is_string($id) && StripeGateway::isPaid($session)) {
                $this->settle($id, StripeGateway::paidCents($session));
            }
        }

        return true;
    }

    /**
     * Registra l'incasso, una volta sola: ogni voce in prima nota con la sua ricevuta.
     */
    public function settle(string $paymentId, int $paidCents): void
    {
        $this->connection()->transaction(function () use ($paymentId, $paidCents): void {
            $this->table()->where('id', $paymentId)->lockForUpdate()->first(['id']);
            $p = $this->find($paymentId);
            if ($p === null || ! in_array($p['status'], ['created', 'pending', 'cancelled', 'failed'], true)) {
                return;
            }
            $settings = $this->settings();
            $errors = [];
            $receipts = [];
            if ($paidCents !== $p['amount_cents']) {
                $errors[] = 'Incassati '.self::euro($paidCents).' invece di '.self::euro($p['amount_cents']).': da controllare.';
            } elseif ($settings?->account_id === null) {
                $errors[] = 'Manca il conto degli incassi online nelle impostazioni.';
            } else {
                foreach ($p['items'] as $item) {
                    $module = $this->owner($item['key']);
                    try {
                        // Ogni voce nella sua transazione: una voce che non si registra non annulla le altre.
                        if ($module === null) {
                            throw new InvalidArgumentException('Voce non riconosciuta.');
                        }
                        $receipt = $this->connection()->transaction(fn (): ?string => $module->settle($item['key'], $item['amount_cents'], (string) $settings->account_id,
                            now('Europe/Rome')->toDateString(), $p['provider']));
                        if ($receipt !== null) {
                            $receipts[] = $receipt;
                        }
                    } catch (Throwable $e) {
                        $errors[] = $item['label'].': '.($e instanceof InvalidArgumentException ? $e->getMessage() : 'non registrata');
                    }
                }
            }
            $this->table()->where('id', $paymentId)->update([
                'status' => $errors === [] ? 'settled' : 'review', 'paid_cents' => $paidCents, 'paid_at' => now(), 'updated_at' => now(),
                'receipts' => json_encode(array_values(array_unique($receipts)), JSON_THROW_ON_ERROR), 'error' => $errors === [] ? null : mb_substr(implode(' ', $errors), 0, 1000),
            ]);
            $this->audit->record($errors === [] ? 'payment.settled' : 'payment.review', 'app.online_payments', $paymentId, ['cents' => $paidCents, 'receipts' => count($receipts)]);
        });
    }

    /**
     * @param  list<string>|null  $personIds  null = tutti (gestionale)
     * @return list<Payment>
     */
    public function list(?array $personIds, int $limit = 100): array
    {
        $q = $this->table()->orderByDesc('created_at')->limit($limit);
        if ($personIds !== null) {
            $q->whereIn('person_id', $personIds);
        }

        return array_values(array_filter(array_map(fn (object $r): ?array => $this->row($r), $q->get()->all())));
    }

    /** La segreteria ha controllato un pagamento «da controllare». */
    public function markReviewed(string $paymentId): void
    {
        $n = $this->table()->where('id', $paymentId)->where('status', 'review')->update(['status' => 'settled', 'reviewed_at' => now(), 'updated_at' => now()]);
        if ($n === 0) {
            throw new MemberAccessError('Pagamento non trovato o già controllato.');
        }
        $this->audit->record('payment.reviewed', 'app.online_payments', $paymentId);
    }

    /** @return Payment|null */
    public function find(string $id): ?array
    {
        $r = Str::isUuid($id) ? $this->table()->where('id', $id)->first() : null;

        return $r === null ? null : $this->row($r);
    }

    /** @return Payment|null */
    private function row(object $r): ?array
    {
        if (! is_string($r->id ?? null) || ! is_string($r->person_id ?? null) || ! is_string($r->items ?? null) || ! is_string($r->receipts ?? null)) {
            return null;
        }
        $items = json_decode($r->items, true);
        $receipts = json_decode($r->receipts, true);
        $clean = [];
        foreach (is_array($items) ? $items : [] as $i) {
            if (is_array($i) && is_string($i['key'] ?? null) && is_string($i['person_id'] ?? null) && is_string($i['label'] ?? null) && is_int($i['amount_cents'] ?? null)) {
                $clean[] = ['key' => $i['key'], 'person_id' => $i['person_id'], 'group' => is_string($i['group'] ?? null) ? $i['group'] : '', 'label' => $i['label'], 'amount_cents' => $i['amount_cents']];
            }
        }

        return [
            'id' => $r->id, 'person_id' => $r->person_id, 'provider' => is_string($r->provider ?? null) ? $r->provider : '', 'status' => is_string($r->status ?? null) ? $r->status : '',
            'amount_cents' => is_numeric($r->amount_cents ?? null) ? (int) $r->amount_cents : 0, 'paid_cents' => is_numeric($r->paid_cents ?? null) ? (int) $r->paid_cents : null,
            'items' => $clean, 'receipts' => array_values(array_filter(is_array($receipts) ? $receipts : [], is_string(...))),
            'provider_ref' => is_string($r->provider_ref ?? null) ? $r->provider_ref : null, 'error' => is_string($r->error ?? null) ? $r->error : null,
            'created_at' => is_string($r->created_at ?? null) ? $r->created_at : '',
        ];
    }

    /** @return list<PayableDues> */
    private function modules(): array
    {
        $out = [];
        foreach ($this->app->tagged(PayableDues::TAG) as $m) {
            if ($m instanceof PayableDues) {
                $out[] = $m;
            }
        }

        return $out;
    }

    private function owner(string $key): ?PayableDues
    {
        foreach ($this->modules() as $m) {
            if ($m->owns($key)) {
                return $m;
            }
        }

        return null;
    }

    private static function euro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection()->table('app.online_payments');
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
