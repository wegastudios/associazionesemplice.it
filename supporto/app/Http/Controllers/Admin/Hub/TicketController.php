<?php

namespace App\Http\Controllers\Admin\Hub;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Ticket;
use App\Services\Hub\SupportSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Casella unificata dei ticket di tutti i portali. */
class TicketController extends Controller
{
    public function index(Request $request, SupportSettings $settings): View
    {
        $filters = $request->validate([
            'site' => 'nullable|string|max:40',
            'status' => 'nullable|in:'.implode(',', config('support.statuses')),
            'priority' => 'nullable|integer|between:0,4',
            'q' => 'nullable|string|max:100',
        ]);

        $tickets = Ticket::query()->with('site')
            ->when($filters['site'] ?? null, fn ($q, $v) => $q->where('site_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($filters['priority']) && $filters['priority'] !== '', fn ($q) => $q->where('priority', (int) $filters['priority']))
            ->when($filters['q'] ?? null, function ($q, $v) {
                $q->where(function ($w) use ($v) {
                    $w->where('subject', 'like', '%'.$v.'%')->orWhere('customer_email', 'like', '%'.$v.'%');
                    if (ctype_digit($v)) {
                        $w->orWhere('id', (int) $v);
                    }
                });
            })
            ->orderByDesc('priority')->orderByDesc('last_activity_at')
            ->paginate(30)->withQueryString();

        return view('admin.hub.tickets.index', [
            'tickets' => $tickets,
            'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
            'labels' => $settings->priorityLabels(),
            'filters' => $filters,
        ]);
    }

    public function show(Ticket $ticket, SupportSettings $settings): View
    {
        return view('admin.hub.tickets.show', [
            'ticket' => $ticket->load('site'),
            'labels' => $settings->priorityLabels(),
        ]);
    }
}
