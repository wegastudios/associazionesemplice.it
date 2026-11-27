<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Controllers;

use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\AreaSoci\Services\CurrentMember;
use App\Modules\AreaSoci\Services\MemberPage;
use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\MemberActivities;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\MemberReceipts;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\DonationInvites;
use App\Modules\Core\Contracts\Input;
use App\Modules\Documenti\Contracts\QrCode;
use App\Modules\Soci\Contracts\MemberProfiles;
use App\Modules\Soci\Contracts\PeopleError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Le pagine del socio: tessera, profilo, quote e ricevute, eventi con prenotazione. */
final readonly class MemberAreaController
{
    private const DAYS = ['lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica'];

    private const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    public function __construct(
        private CurrentMember $current,
        private MemberPage $memberPage,
        private MemberProfiles $profiles,
        private MemberReceipts $receipts,
        private MemberActivities $activities,
        private OnlinePayments $payments,
        private DonationInvites $invites,
    ) {}

    public function card(Request $request): Response
    {
        $card = $this->profiles->card($this->current->active()) ?? abort(404);
        // Il link del codice della tessera resta sullo stesso indirizzo da cui il socio è entrato (anche il dominio proprio).
        $custom = str_starts_with((string) $request->route()?->getName(), 'custom.');
        $verify = $card['member'] ? ($custom
            ? URL::signedRoute('custom.member.verify', ['host' => $request->getHost(), 'person' => $card['id'], 'v' => $card['qr_version']])
            : URL::signedRoute('member.verify', ['org' => $this->current->require()['org_slug'], 'person' => $card['id'], 'v' => $card['qr_version']])) : null;

        $dues = $this->payments->dues($this->current->ids());

        return $this->page('AreaSoci/Tessera', [
            'card' => $card,
            'due' => $dues === [] ? null : ['count' => count($dues), 'total' => Money::format((int) array_sum(array_column($dues, 'amount_cents')))],
            'qr' => $verify === null ? null : QrCode::svg($verify, 4, 'Codice QR della tessera'),
        ]);
    }

    public function profile(Request $request, TwoFactorController $twoFactor): Response
    {
        $id = $this->current->active();

        return $this->page('AreaSoci/Profilo', [
            'card' => $this->profiles->card($id) ?? abort(404),
            'details' => $this->profiles->details($id) ?? abort(404),
            // Verifica in due passaggi: della scheda di chi è entrato (non di un minore aperto da un tutore).
            'twoFactor' => $twoFactor->profileData($request, AccessController::org($request)['name']),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate(['phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:255']], [], ['phone' => 'telefono']));
        try {
            $this->profiles->updateContacts($this->current->active(), Input::optionalString($data, 'phone'), Input::optionalString($data, 'address'));
        } catch (PeopleError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', 'Recapiti aggiornati.');
    }

    public function regenerateCard(): RedirectResponse
    {
        try {
            $this->profiles->regenerateCardCode($this->current->active());
        } catch (PeopleError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', 'Codice rigenerato: quello vecchio non vale più.');
    }

    public function payments(): Response
    {
        $ids = [$this->current->active()];

        return $this->page('AreaSoci/Quote', [
            'details' => $this->profiles->details($this->current->active()) ?? abort(404),
            'enrollments' => array_map(static fn (array $e): array => $e + ['due_label' => Money::format($e['due']), 'paid_label' => Money::format($e['paid']),
                'missing' => Money::format(max(0, $e['due'] - $e['paid']))], $this->activities->enrollments($ids)),
            'receipts' => array_map(static fn (array $r): array => $r + ['total_label' => Money::format($r['total'])], $this->receipts->forPeople($ids)),
        ]);
    }

    public function receipt(string $receipt): HttpResponse
    {
        try {
            $doc = $this->receipts->pdf($receipt, $this->current->ids()) ?? abort(404);
        } catch (LedgerError $e) {
            return response($e->getMessage(), 409, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response($doc['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$doc['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function events(): Response
    {
        return $this->page('AreaSoci/Eventi', [
            'online' => $this->payments->providers() !== [],
            'justBooked' => session()->pull('invite_after_booking') === true && $this->invites->enabled(DonationInvites::CONFIRM),
            'events' => array_map(fn (array $e): array => $e + [
                'label' => $this->rangeLabel($e['date'], $e['end_date']), 'closed_label' => $e['closed'] ? 'Prenotazioni online chiuse: per questa data rivolgiti alla segreteria.' : null, 'fee_label' => Money::format($e['fee']), 'due' => max(0, $e['fee'] - $e['paid']),
            ], $this->activities->upcoming($this->current->active())),
        ]);
    }

    public function book(string $session): RedirectResponse
    {
        $done = $this->attempt(fn () => $this->activities->book($session, $this->current->active()), $this->payments->providers() === [] ? 'Prenotazione registrata: il contributo si paga in segreteria.' : 'Prenotazione registrata: il contributo si paga in segreteria o da «Quote».');

        // Se la prenotazione è riuscita (altrimenti attempt() ha già fermato tutto), la pagina può invitare a donare.
        return $done->with('invite_after_booking', true);
    }

    public function cancel(string $booking): RedirectResponse
    {
        return $this->attempt(fn () => $this->activities->cancel($booking, $this->current->active()), 'Prenotazione annullata.');
    }

    public function waitlist(string $session): RedirectResponse
    {
        return $this->attempt(fn () => $this->activities->joinWaitlist($session, $this->current->active()), 'Sei in lista d\'attesa: se si libera un posto diventa tuo e ricevi un\'email.');
    }

    /** La pagina che si apre leggendo il QR della tessera: dice solo se la tessera è valida oggi. */
    public function verify(Request $request, string $person): Response
    {
        $card = $this->profiles->card($person);
        // Il codice vale solo se porta la versione attuale: chi l'ha rigenerato ha invalidato quello vecchio (senza versione = la prima).
        $given = $request->query('v');
        if ($card !== null && (is_string($given) ? (int) $given : 1) !== $card['qr_version']) {
            $card = null;
        }
        $org = AccessController::org($request);

        return Inertia::render('AreaSoci/Verifica', [
            'orgName' => $org['name'],
            'card' => $card === null ? null : [
                'name' => $card['first_name'].' '.mb_substr($card['last_name'], 0, 1).'.', 'number' => $card['card_number'],
                'active' => $card['active'] && ! $card['suspended'], 'year' => $card['year'],
            ],
        ]);
    }

    /** @param  array<string, mixed>  $props */
    private function page(string $component, array $props): Response
    {
        return Inertia::render($component, $props + ['member' => $this->memberPage->props()]);
    }

    /** @param  callable(): mixed  $action */
    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (ActivityError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }

    /** «dal 12 al 14 giugno», «dal 30 maggio al 2 giugno»; una data di un giorno è «sabato 13 giugno». */
    private function rangeLabel(string $date, ?string $endDate): string
    {
        if ($endDate === null) {
            return $this->dayLabel($date);
        }
        $a = (int) strtotime($date);
        $b = (int) strtotime($endDate);
        $from = self::MONTHS[(int) date('n', $a) - 1];
        $to = self::MONTHS[(int) date('n', $b) - 1];

        return 'dal '.date('j', $a).($from === $to ? '' : ' '.$from).' al '.date('j', $b).' '.$to;
    }

    private function dayLabel(string $date): string
    {
        $t = (int) strtotime($date);

        return self::DAYS[(int) date('N', $t) - 1].' '.date('j', $t).' '.self::MONTHS[(int) date('n', $t) - 1];
    }
}
