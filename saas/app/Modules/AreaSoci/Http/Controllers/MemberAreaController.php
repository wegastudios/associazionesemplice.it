<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Controllers;

use App\Modules\AreaSoci\Services\CurrentMember;
use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\MemberActivities;
use App\Modules\Contabilita\Contracts\MemberReceipts;
use App\Modules\Contabilita\Contracts\Money;
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
        private MemberProfiles $profiles,
        private MemberReceipts $receipts,
        private MemberActivities $activities,
    ) {}

    public function card(Request $request): Response
    {
        $card = $this->profiles->card($this->current->active()) ?? abort(404);
        $verify = $card['member'] ? URL::signedRoute('member.verify', ['org' => $this->current->require()['org_slug'], 'person' => $card['id']]) : null;

        return $this->page('AreaSoci/Tessera', [
            'card' => $card,
            'qr' => $verify === null ? null : QrCode::svg($verify, 4, 'Codice QR della tessera'),
        ]);
    }

    public function profile(): Response
    {
        $id = $this->current->active();

        return $this->page('AreaSoci/Profilo', [
            'card' => $this->profiles->card($id) ?? abort(404),
            'details' => $this->profiles->details($id) ?? abort(404),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate(['phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:255']]));
        try {
            $this->profiles->updateContacts($this->current->active(), Input::optionalString($data, 'phone'), Input::optionalString($data, 'address'));
        } catch (PeopleError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', 'Recapiti aggiornati.');
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
        $doc = $this->receipts->pdf($receipt, $this->current->ids()) ?? abort(404);

        return response($doc['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$doc['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function events(): Response
    {
        return $this->page('AreaSoci/Eventi', [
            'events' => array_map(fn (array $e): array => $e + [
                'label' => $this->dayLabel($e['date']), 'fee_label' => Money::format($e['fee']), 'due' => max(0, $e['fee'] - $e['paid']),
            ], $this->activities->upcoming($this->current->active())),
        ]);
    }

    public function book(string $session): RedirectResponse
    {
        return $this->attempt(fn () => $this->activities->book($session, $this->current->active()), 'Prenotazione registrata: il contributo si paga in segreteria.');
    }

    public function cancel(string $booking): RedirectResponse
    {
        return $this->attempt(fn () => $this->activities->cancel($booking, $this->current->active()), 'Prenotazione annullata.');
    }

    public function waitlist(string $session): RedirectResponse
    {
        return $this->attempt(fn () => $this->activities->joinWaitlist($session, $this->current->active()), 'Sei in lista d\'attesa: se si libera un posto entri da solo.');
    }

    /** La pagina che si apre leggendo il QR della tessera: dice solo se la tessera è valida oggi. */
    public function verify(Request $request, string $person): Response
    {
        $card = $this->profiles->card($person);
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
        $m = $this->current->require();

        return Inertia::render($component, $props + [
            'member' => ['org' => $m['org_name'], 'active' => $m['active'], 'profiles' => $m['profiles']],
        ]);
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

    private function dayLabel(string $date): string
    {
        $t = (int) strtotime($date);

        return self::DAYS[(int) date('N', $t) - 1].' '.date('j', $t).' '.self::MONTHS[(int) date('n', $t) - 1];
    }
}
