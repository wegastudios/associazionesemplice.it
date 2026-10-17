<?php

namespace App\Http\Controllers\Admin\Site;

use App\Exceptions\HubRejectedException;
use App\Exceptions\HubUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Site\HubClient;
use App\Services\Site\TicketRegistrar;
use App\Services\Site\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Coda dei ticket del portale per lo staff. */
class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => 'nullable|in:'.implode(',', config('support.statuses')),
            'priority' => 'nullable|integer|between:0,4',
            'q' => 'nullable|string|max:100',
            'pending' => 'nullable|boolean',
        ]);

        $tickets = SupportTicket::query()->with('topic')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($filters['priority']) && $filters['priority'] !== '', fn ($q) => $q->where('priority', (int) $filters['priority']))
            ->when(! empty($filters['pending']), fn ($q) => $q->whereNull('hub_id'))
            ->when($filters['q'] ?? null, function ($q, $v) {
                $q->where(function ($w) use ($v) {
                    $w->where('subject', 'like', '%'.$v.'%')->orWhere('customer_email', 'like', '%'.$v.'%');
                    if (ctype_digit($v)) {
                        $w->orWhere('hub_id', (int) $v);
                    }
                });
            })
            ->orderByDesc('priority')->orderByDesc('last_activity_at')
            ->paginate(30)->withQueryString();

        return view('admin.site.tickets.index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'pendingCount' => SupportTicket::query()->whereNull('hub_id')->count(),
            'labels' => config('support.priority_labels'),
        ]);
    }

    public function show(SupportTicket $ticket, HubClient $hub): View
    {
        return view('admin.site.tickets.show', [
            'ticket' => $ticket->load('messages', 'topic', 'plan', 'assignee'),
            'staff' => User::query()->where('global_role', 'admin')->orderBy('name')->get(['id', 'name']),
            'allowed' => $hub->allowedPriorities(),
            'labels' => config('support.priority_labels'),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:10000', 'note' => 'nullable|boolean']);
        $tickets->staffReply($ticket, $request->user(), $data['body'], (bool) ($data['note'] ?? false));

        return back()->with('ok', ($data['note'] ?? false) ? 'Nota interna salvata.' : 'Risposta inviata.');
    }

    public function status(Request $request, SupportTicket $ticket, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:'.implode(',', config('support.statuses'))]);

        return $this->guard(fn () => $tickets->setStatus($ticket, $data['status'], $request->user()), 'Stato aggiornato.');
    }

    public function priority(Request $request, SupportTicket $ticket, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate(['priority' => 'required|integer|between:0,4']);

        return $this->guard(fn () => $tickets->setPriority($ticket, (int) $data['priority'], $request->user()), 'Priorità aggiornata.');
    }

    /** Dati solo locali: assegnatario e priorità interna del portale. */
    public function manage(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'assigned_to' => 'nullable|integer|exists:users,id',
            'internal_priority' => 'nullable|string|max:40',
        ]);

        $ticket->forceFill(['assigned_to' => $data['assigned_to'] ?? null, 'internal_priority' => $data['internal_priority'] ?? null])->save();

        return back()->with('ok', 'Salvato.');
    }

    /** Riprova subito la registrazione presso l'hub di un ticket in attesa. */
    public function register(SupportTicket $ticket, TicketRegistrar $registrar): RedirectResponse
    {
        return $registrar->register($ticket)
            ? back()->with('ok', "Ticket registrato: numero {$ticket->hub_id}.")
            : back()->withErrors(['hub' => 'Registrazione non riuscita: '.($ticket->registration_error ?? 'hub non raggiungibile')]);
    }

    /** Esegue un'azione che passa dall'hub e traduce i rifiuti in messaggi per l'operatore. */
    private function guard(callable $action, string $okMessage): RedirectResponse
    {
        try {
            $action();
        } catch (HubRejectedException $e) {
            return back()->withErrors(['hub' => match ($e->errorCode) {
                'REOPEN_DISABLED' => 'Questo portale non può riaprire i ticket chiusi: apri un nuovo ticket.',
                'PRIORITY_NOT_ALLOWED' => 'Livello di priorità non consentito a questo portale.',
                default => $e->getMessage(),
            }]);
        } catch (HubUnavailableException $e) {
            return back()->withErrors(['hub' => 'Hub non raggiungibile: modifica non applicata. '.$e->getMessage()]);
        }

        return back()->with('ok', $okMessage);
    }
}
