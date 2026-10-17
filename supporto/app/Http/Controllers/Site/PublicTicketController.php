<?php

namespace App\Http\Controllers\Site;

use App\Exceptions\HubRejectedException;
use App\Exceptions\HubUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SupportTicket;
use App\Models\Topic;
use App\Services\Site\CustomerLink;
use App\Services\Site\HumanCheck;
use App\Services\Site\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Modulo ticket pubblico e pagina del ticket per il cliente (link firmato). */
class PublicTicketController extends Controller
{
    public function create(Request $request, HumanCheck $human): View
    {
        return view('site.ticket-new', [
            'formToken' => $human->issue(),
            'privacyUrl' => config('support.privacy.url'),
            'topics' => Topic::query()->where('is_public', true)->orderBy('sort')->orderBy('name')->get(),
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
            'related' => $request->query('rif'),
        ]);
    }

    public function store(Request $request, TicketService $tickets, HumanCheck $human): RedirectResponse
    {
        // Controllo anti-bot: campo trappola compilato = scarto silenzioso (il bot crede di aver inviato)
        $check = $human->verify($request);
        if ($check === HumanCheck::BOT) {
            return redirect()->route('ticket.received');
        }
        if ($check !== HumanCheck::OK) {
            $message = $check === HumanCheck::TOO_FAST
                ? 'Il modulo è stato inviato troppo in fretta: attendi qualche secondo e premi di nuovo Invia.'
                : 'Il modulo è scaduto: ricarica la pagina e invia di nuovo la richiesta.';

            // Il testo scritto resta nel modulo; il modulo ricaricato porta una marca nuova
            return back()->withInput($request->except(HumanCheck::TOKEN_FIELD))->withErrors(['form' => $message]);
        }

        $data = $request->validate([
            'privacy_accepted' => 'accepted',
            'customer_name' => 'required|string|max:150',
            'customer_email' => 'required|email|max:190',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|min:10|max:10000',
            'topic_id' => 'nullable|integer|exists:topics,id',
            'plan_id' => 'nullable|integer|exists:plans,id',
            'related_hub_id' => 'nullable|integer|min:1',
        ], [
            'privacy_accepted.accepted' => 'Per inviare la richiesta devi accettare l\'informativa sulla privacy e il trattamento dei dati.',
        ]);
        unset($data['privacy_accepted']);

        $ticket = $tickets->open([
            ...$data,
            // Prova dell'accettazione esplicita: data e versione dell'informativa mostrata
            'privacy_accepted_at' => now(),
            'privacy_version' => (string) config('support.privacy.version'),
            'portal_context' => ['user_agent' => mb_substr((string) $request->userAgent(), 0, 200)],
        ]);

        // Con l'id assegnato il cliente va alla pagina del ticket; senza (hub non raggiungibile) vede la conferma di ricezione
        return $ticket->isRegistered()
            ? redirect(CustomerLink::show($ticket))->with('ok', "Richiesta ricevuta: il tuo ticket è il numero {$ticket->hub_id}. Ti abbiamo scritto una conferma per email.")
            : redirect()->route('ticket.received');
    }

    public function received(): View
    {
        return view('site.ticket-received');
    }

    public function show(int $hubId): View
    {
        $ticket = $this->find($hubId)->load('messages', 'topic');

        return view('site.ticket', [
            'ticket' => $ticket,
            'messages' => $ticket->messages->where('author_type', '!=', 'note'),
            'replyUrl' => CustomerLink::reply($ticket),
        ]);
    }

    public function reply(Request $request, int $hubId, TicketService $tickets): RedirectResponse
    {
        $ticket = $this->find($hubId);
        $data = $request->validate(['body' => 'required|string|min:2|max:10000']);

        try {
            $tickets->customerReply($ticket, $data['body']);
        } catch (HubRejectedException $e) {
            if ($e->errorCode === 'REOPEN_DISABLED') {
                return back()->withErrors(['body' => 'Questo ticket è chiuso e non può essere riaperto: apri una nuova richiesta.'])
                    ->with('new_ticket_url', route('ticket.create', ['rif' => $ticket->hub_id]));
            }

            return back()->withInput()->withErrors(['body' => 'Non è stato possibile inviare la risposta. Riprova più tardi.']);
        } catch (HubUnavailableException) {
            return back()->withInput()->withErrors(['body' => 'Il servizio è momentaneamente non raggiungibile: riprova tra qualche minuto.']);
        }

        return redirect(CustomerLink::show($ticket))->with('ok', 'Risposta inviata.');
    }

    private function find(int $hubId): SupportTicket
    {
        return SupportTicket::query()->where('hub_id', $hubId)->firstOrFail();
    }
}
