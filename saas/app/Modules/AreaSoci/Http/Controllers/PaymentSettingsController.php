<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Controllers;

use App\Modules\AreaSoci\Models\PaymentSettings;
use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\AreaSoci\Payments\PayPalGateway;
use App\Modules\AreaSoci\Payments\StripeGateway;
use App\Modules\AreaSoci\Services\MemberAccessError;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Input;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/** Gestionale: i conti Stripe e PayPal dell'associazione e l'elenco dei pagamenti online dei soci. */
final readonly class PaymentSettingsController
{
    public function __construct(
        private OnlinePayments $payments,
        private Ledger $ledger,
        private StripeGateway $stripe,
        private PayPalGateway $paypal,
        private OrgContext $context,
        private DatabaseManager $db,
    ) {}

    public function index(Request $request): Response
    {
        $s = $this->payments->settings();
        $names = [];
        $list = $this->payments->list(null, 200);
        foreach ($this->db->connection($this->context->require()->connection)->table('app.people')->whereIn('id', array_unique(array_column($list, 'person_id')))->get(['id', 'first_name', 'last_name']) as $p) {
            if (is_string($p->id ?? null)) {
                $names[$p->id] = trim((is_string($p->last_name ?? null) ? $p->last_name : '').' '.(is_string($p->first_name ?? null) ? $p->first_name : ''));
            }
        }

        return Inertia::render('AreaSoci/PagamentiOnline', [
            'settings' => [
                'stripe_enabled' => $s->stripe_enabled ?? false, 'stripe_secret_set' => $s?->stripe_secret !== null, 'stripe_webhook_set' => $s?->stripe_webhook !== null,
                'paypal_enabled' => $s->paypal_enabled ?? false, 'paypal_client_id' => $s->paypal_client_id ?? '', 'paypal_secret_set' => $s?->paypal_secret !== null,
                'paypal_live' => $s->paypal_live ?? false, 'account_id' => $s->account_id ?? '', 'ready' => $s?->providers() ?? [],
            ],
            'webhookUrl' => $this->webhookUrl(),
            'accounts' => $this->ledger->openAccounts(),
            'payments' => array_map(static fn (array $p): array => [
                'id' => $p['id'], 'date' => date('d/m/Y H:i', (int) strtotime($p['created_at'])), 'person' => $names[$p['person_id']] ?? '', 'person_id' => $p['person_id'],
                'provider' => $p['provider'] === 'stripe' ? 'Stripe' : 'PayPal', 'status' => $p['status'], 'amount' => Money::format($p['amount_cents']),
                'items' => implode(', ', array_column($p['items'], 'label')), 'receipts' => implode(', ', $p['receipts']), 'error' => $p['error'],
                'refundable' => $p['status'] === 'review' && $p['paid_cents'] !== null ? max(0, $p['paid_cents'] - $p['refunded_cents']) : 0, 'refunded' => Money::format($p['refunded_cents']), 'has_receipts' => $p['receipts'] !== [], 'stays_note' => OnlinePayments::staysNote($p['items']),
                'voidable' => $p['receipts'] !== [] && in_array($p['status'], ['settled', 'review'], true) && $p['paid_cents'] !== null && $p['paid_cents'] - $p['refunded_cents'] > 0,
            ], $list),
            'canSettings' => $request->user()?->can('org.settings') ?? false,
            'canAccounting' => $request->user()?->can('accounting.edit') ?? false,
            'canRefund' => $request->user()?->can('accounting.refund') ?? false,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate([
            'stripe_enabled' => ['boolean'],
            'stripe_secret' => ['nullable', 'string', 'max:255', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
            'stripe_webhook' => ['nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9]+$/'],
            'paypal_enabled' => ['boolean'],
            'paypal_client_id' => ['nullable', 'string', 'max:200'],
            'paypal_secret' => ['nullable', 'string', 'max:255'],
            'paypal_live' => ['boolean'],
            'account_id' => ['nullable', 'uuid'],
            'forget' => ['array'],
            'forget.*' => ['in:stripe_secret,stripe_webhook,paypal_secret'],
        ]));
        $account = Input::optionalString($data, 'account_id');
        if ($account !== null && ! in_array($account, array_column($this->ledger->openAccounts(), 'id'), true)) {
            throw ValidationException::withMessages(['account_id' => 'Conto non valido.']);
        }
        $s = $this->payments->settings() ?? new PaymentSettings;
        $newStripe = Input::optionalString($data, 'stripe_secret') !== null;
        $newPaypal = Input::optionalString($data, 'paypal_secret') !== null || (Input::optionalString($data, 'paypal_client_id') ?? '') !== (is_string($s->paypal_client_id ?? null) ? $s->paypal_client_id : '');
        $s->fill([
            'stripe_enabled' => Input::bool($data, 'stripe_enabled', false), 'paypal_enabled' => Input::bool($data, 'paypal_enabled', false),
            'paypal_client_id' => Input::optionalString($data, 'paypal_client_id'), 'paypal_live' => Input::bool($data, 'paypal_live', false), 'account_id' => $account,
        ]);
        // Un segreto vuoto resta com'è; si toglie solo chiedendolo.
        foreach (['stripe_secret', 'stripe_webhook', 'paypal_secret'] as $k) {
            $v = Input::optionalString($data, $k);
            if ($v !== null) {
                $s->setAttribute($k, $v);
            }
        }
        foreach (is_array($data['forget'] ?? null) ? $data['forget'] : [] as $k) {
            if (is_string($k)) {
                $s->setAttribute($k, null);
            }
        }
        $s->save();

        // Subito dopo le chiavi nuove: prova della connessione e, per Stripe, webhook creato da solo.
        $done = [];
        $problems = [];
        if ($newStripe && is_string($s->stripe_secret) && $s->stripe_secret !== '') {
            try {
                $this->stripe->test($s->stripe_secret);
                $done[] = 'La connessione con Stripe funziona.';
                if (($s->stripe_webhook ?? '') === '') {
                    [$ok, $text] = $this->createStripeWebhook($s);
                    if ($ok) {
                        $done[] = $text;
                    } else {
                        $problems[] = $text;
                    }
                }
            } catch (Throwable $e) {
                $problems[] = 'La prova della connessione con Stripe non è riuscita: '.$e->getMessage();
            }
        }
        if ($newPaypal && is_string($s->paypal_client_id) && $s->paypal_client_id !== '' && is_string($s->paypal_secret) && $s->paypal_secret !== '') {
            try {
                $this->paypal->token($s->paypal_live ?? false, $s->paypal_client_id, $s->paypal_secret);
                $done[] = 'La connessione con PayPal funziona.';
            } catch (Throwable $e) {
                $problems[] = 'La prova della connessione con PayPal non è riuscita: '.$e->getMessage();
            }
        }

        $back = back()->with('status', trim('Impostazioni dei pagamenti salvate. '.implode(' ', $done)));

        return $problems === [] ? $back : $back->withErrors(['ledger' => implode(' ', $problems)]);
    }

    /** @return array{0: bool, 1: string} se è riuscito e cosa dire */
    private function createStripeWebhook(PaymentSettings $s): array
    {
        try {
            $webhook = $this->stripe->createWebhook((string) $s->stripe_secret, $this->webhookUrl());
        } catch (Throwable $e) {
            return [false, 'Non sono riuscito a creare il webhook di Stripe da solo ('.$e->getMessage().'): aggiungilo a mano come indicato sotto.'];
        }
        if ($webhook === null) {
            return [false, "Su Stripe c'è già un webhook per questo indirizzo: Stripe non mostra più il suo segreto, copialo a mano dal pannello di Stripe."];
        }
        $s->stripe_webhook = $webhook['secret'];
        $s->save();

        return [true, 'Il webhook di Stripe è stato creato.'];
    }

    private function webhookUrl(): string
    {
        $slug = $this->db->connection('pgsql')->table('core.orgs')->where('id', $this->context->require()->id)->value('slug');

        return StaffLinkController::baseUrl(is_string($slug) ? $slug : '').'/area/webhook/stripe';
    }

    public function test(string $provider): RedirectResponse
    {
        $s = $this->payments->settings();
        try {
            if ($provider === 'stripe') {
                $this->stripe->test((string) ($s->stripe_secret ?? throw new RuntimeException('Manca la chiave segreta di Stripe.')));
            } else {
                $this->paypal->token($s->paypal_live ?? false, (string) ($s->paypal_client_id ?? throw new RuntimeException('Manca il Client ID di PayPal.')),
                    (string) ($s->paypal_secret ?? throw new RuntimeException('Manca il segreto di PayPal.')));
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['ledger' => 'Prova non riuscita: '.$e->getMessage()]);
        }

        return back()->with('status', 'Prova riuscita: le chiavi funzionano.');
    }

    public function reviewed(string $payment): RedirectResponse
    {
        try {
            $this->payments->markReviewed($payment);
        } catch (MemberAccessError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', 'Pagamento segnato come controllato.');
    }

    public function refund(Request $request, string $payment): RedirectResponse
    {
        $data = Input::of($request->validate(['amount' => ['nullable', 'string', 'max:20'], 'reason' => ['required', 'string', 'max:200']]));
        $amount = Input::optionalString($data, 'amount');
        $cents = $amount === null ? null : Money::parse($amount);
        if ($amount !== null && ($cents === null || $cents <= 0)) {
            throw ValidationException::withMessages(['amount' => 'Importo non valido.']);
        }
        $name = $this->db->connection('pgsql')->table('core.orgs')->where('id', $this->context->require()->id)->value('name');
        $before = $cents === null ? $this->payments->find($payment) : null;
        try {
            $mailed = $this->payments->refund($payment, $cents, Input::string($data, 'reason'), is_string($name) ? $name : '');
        } catch (MemberAccessError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        $notice = $mailed ? 'Il socio è stato avvisato per email.' : 'Il socio non ha un\'email o l\'invio non è riuscito: avvisalo tu.';

        // Dopo lo storno, prenotazioni e iscrizioni restano e tornano da pagare: lo si dice chiaro, senza lasciarlo indovinare.
        $stays = $before === null ? '' : OnlinePayments::staysNote($before['items']);

        return back()->with('status', ($cents === null ? 'Rimborsato e annullato: ricevute e tessere comprese.' : 'Rimborso di '.Money::format($cents).' inviato al socio. ').($stays !== '' ? $stays.' ' : '').$notice);
    }
}
