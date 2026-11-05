<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Controllers;

use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\AreaSoci\Services\CurrentMember;
use App\Modules\AreaSoci\Services\MemberAccessError;
use App\Modules\AreaSoci\Services\MemberPage;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Input;
use App\Modules\Soci\Contracts\MemberProfiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Area soci: le voci da pagare, il pagamento online, l'esito; il webhook di Stripe. */
final readonly class MemberPaymentsController
{
    private const STATUS = ['created' => 'avviato', 'pending' => 'in attesa', 'settled' => 'pagato', 'review' => 'pagato, in verifica', 'failed' => 'non riuscito', 'cancelled' => 'annullato'];

    public function __construct(
        private OnlinePayments $payments,
        private CurrentMember $current,
        private MemberPage $memberPage,
        private MemberProfiles $profiles,
    ) {}

    public function index(): Response
    {

        return Inertia::render('AreaSoci/Paga', [
            'member' => $this->memberPage->props(),
            'providers' => $this->payments->providers(),
            'dues' => array_map(static fn (array $d): array => $d + ['amount_label' => Money::format($d['amount_cents'])], $this->payments->dues($this->current->ids())),
            'history' => array_map(static fn (array $p): array => [
                'id' => $p['id'], 'date' => date('d/m/Y', (int) strtotime($p['created_at'])), 'amount' => Money::format($p['amount_cents']),
                'provider' => $p['provider'] === 'stripe' ? 'Carta' : 'PayPal', 'status' => self::STATUS[$p['status']] ?? $p['status'], 'receipts' => $p['receipts'],
                'items' => implode(', ', array_column($p['items'], 'label')),
            ], array_slice($this->payments->list($this->current->ids()), 0, 20)),
        ]);
    }

    public function checkout(Request $request): SymfonyResponse
    {
        $data = Input::of($request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:50'],
            'keys.*' => ['string', 'max:120'],
            'provider' => ['required', Rule::in(['stripe', 'paypal'])],
        ]));
        $m = $this->current->require();
        $keys = array_values(array_filter(is_array($data['keys'] ?? null) ? $data['keys'] : [], is_string(...)));
        try {
            $url = $this->payments->checkout($m['person'], $this->current->ids(), $keys, Input::string($data, 'provider'),
                $this->profiles->details($m['person'])['email'] ?? null, $request->getSchemeAndHttpHost(), $m['org_name']);
        } catch (MemberAccessError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return Inertia::location($url);
    }

    public function result(string $payment): Response
    {
        $m = $this->current->require();
        $p = $this->payments->confirm($payment, $m['person']) ?? abort(404);

        return Inertia::render('AreaSoci/Esito', [
            'member' => $this->memberPage->props(),
            'payment' => ['amount' => Money::format($p['amount_cents']), 'status' => $p['status'], 'label' => self::STATUS[$p['status']] ?? $p['status'],
                'items' => array_column($p['items'], 'label'), 'receipts' => $p['receipts']],
        ]);
    }

    public function cancelled(string $payment): RedirectResponse
    {
        $this->payments->cancel($payment, $this->current->require()['person']);

        return redirect('/area/paga')->with('status', 'Pagamento annullato: non ti è stato addebitato nulla.');
    }

    /** Stripe avvisa che un pagamento è andato a buon fine. Senza firma valida: 400. */
    public function stripeWebhook(Request $request): HttpResponse
    {
        $ok = $this->payments->stripeWebhook($request->getContent(), (string) $request->header('Stripe-Signature', ''));

        return response($ok ? 'ok' : 'firma non valida', $ok ? 200 : 400);
    }
}
