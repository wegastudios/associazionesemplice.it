<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Controllers;

use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\AreaSoci\Services\MemberAccessError;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Input;
use App\Modules\Core\Contracts\OrgContacts;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Contracts\OrgDonations;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Contracts\Supporters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Pagina pubblica di donazione: /dona/{indirizzo dell'associazione}, senza login e aperta anche ai non soci. Esiste solo se
 * l'associazione è attiva, ha acceso la spunta delle donazioni e ha un metodo di pagamento attivo. Chi dona è un socio
 * (se l'email è nota) o un «Sostenitore» non socio; il pagamento passa dallo stesso checkout dei soci.
 */
final readonly class PublicDonationController
{
    private const PER_EMAIL = 5;

    public function __construct(
        private OrgDirectory $directory,
        private OrgContext $context,
        private OrgSettings $settings,
        private OrgContacts $contacts,
        private OrgDonations $donations,
        private OnlinePayments $payments,
        private Supporters $supporters,
    ) {}

    public function show(string $slug): Response
    {
        $org = $this->enter($slug);

        return Inertia::render('AreaSoci/Dona', [
            'org' => ['name' => $org['name'], 'slug' => $slug],
            'amount' => number_format($this->donations->amountCents() / 100, 2, ',', ''),
            'providers' => $this->payments->providers(),
            'deductible' => $this->donations->deductible(),
            'privacyUrl' => $this->url('soci.privacy_url'),
            'contacts' => $this->contacts->all(),
        ]);
    }

    public function store(Request $request, string $slug): SymfonyResponse
    {
        $org = $this->enter($slug);

        // Campo trappola: le persone non lo vedono, i programmi automatici lo riempiono. Stessa risposta di un invio mancato.
        if (trim($request->string('website')->toString()) !== '') {
            return redirect()->route('donation.form', $slug);
        }
        $data = Input::of($request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'tax_code' => ['nullable', 'string', 'max:20'],
            'amount' => ['required', 'string', 'max:12'],
            'provider' => ['required', Rule::in(['stripe', 'paypal'])],
            'privacy_ok' => ['accepted'],
        ], [
            'first_name.required' => 'Scrivi nome e cognome.', 'last_name.required' => 'Scrivi nome e cognome.',
            'email.required' => 'Scrivi un indirizzo email valido: ci serve per mandarti la ricevuta.', 'email.email' => 'Scrivi un indirizzo email valido: ci serve per mandarti la ricevuta.',
            'amount.required' => 'Scegli un importo tra 1,00 € e 1.000,00 €.', 'provider.required' => 'Scegli come pagare.', 'provider.in' => 'Scegli come pagare.',
            'privacy_ok.accepted' => 'Per donare serve aver letto l\'informativa.',
        ]));
        $raw = str_replace(',', '.', trim(Input::string($data, 'amount')));
        $cents = is_numeric($raw) ? (int) round((float) $raw * 100) : 0;
        if ($cents < OrgDonations::MIN_CENTS || $cents > OrgDonations::MAX_CENTS) {
            throw ValidationException::withMessages(['amount' => 'Scegli un importo tra 1,00 € e 1.000,00 €.']);
        }

        // Pochi tentativi per la stessa email, oltre al limite per indirizzo e per associazione della rotta.
        $email = Str::lower(trim(Input::string($data, 'email')));
        $key = 'donation-email|'.$org['id'].'|'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, self::PER_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Troppi tentativi: riprova più tardi.']);
        }
        RateLimiter::hit($key, 3600);

        try {
            $person = $this->supporters->personFor(Input::string($data, 'first_name'), Input::string($data, 'last_name'), $email, Input::optionalString($data, 'tax_code'));
            $url = $this->payments->checkout($person, [], [], Input::string($data, 'provider'), $email, $request->getSchemeAndHttpHost(), $org['name'], $cents, $slug);
        } catch (PeopleError $e) {
            throw ValidationException::withMessages(['tax_code' => 'Il codice fiscale non sembra corretto: controllalo oppure lascialo vuoto.']);
        } catch (MemberAccessError $e) {
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        return Inertia::location($url);
    }

    public function result(string $slug, string $payment): Response
    {
        $this->enter($slug);
        $p = $this->payments->confirmPublic($payment) ?? abort(404);

        return Inertia::render('AreaSoci/DonaEsito', [
            'org' => ['name' => $this->directory->activeBySlug($slug)['name'] ?? '', 'slug' => $slug],
            'payment' => ['amount' => Money::format($p['amount_cents']), 'status' => $p['status'], 'receipts' => $p['receipts']],
        ]);
    }

    public function cancelled(string $slug, string $payment): SymfonyResponse
    {
        $this->enter($slug);
        $this->payments->cancelPublic($payment);

        return redirect()->route('donation.form', $slug)->with('status', 'Pagamento annullato: non è stato addebitato nulla.');
    }

    /** @return array{id: string, name: string} */
    private function enter(string $slug): array
    {
        $org = $this->directory->activeBySlug($slug);
        abort_if($org === null, 404);
        $this->context->enter($org['id']);
        abort_unless($this->donations->checkout() && $this->payments->providers() !== [], 404);

        return $org;
    }

    private function url(string $key): ?string
    {
        $value = $this->settings->get($key);

        return is_string($value) && str_starts_with($value, 'https://') ? $value : null;
    }
}
