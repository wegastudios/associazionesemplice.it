<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Ticket;
use App\Services\Hub\SupportSettings;
use App\Services\Hub\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** API dell'hub per i siti di supporto (autenticati con Bearer client_id.segreto). */
class HubTicketController extends Controller
{
    /** Regole del portale: priorita consentite, riapertura, etichette dei livelli. */
    public function rules(Request $request, SupportSettings $settings): JsonResponse
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');
        $labels = $settings->priorityLabels();

        return response()->json(['data' => [
            'allowed_priorities' => $site->priorities(),
            'allow_reopen' => $site->allow_reopen,
            'priority_labels' => array_intersect_key($labels, array_flip($site->priorities())),
            'statuses' => config('support.statuses'),
        ]]);
    }

    /** Registra un ticket e restituisce il suo id (201 se nuovo, 200 se gia registrato con lo stesso request_uuid). */
    public function store(Request $request, TicketService $tickets): JsonResponse
    {
        $data = $request->validate([
            'request_uuid' => 'required|uuid',
            'subject' => 'required|string|max:200',
            'priority' => 'nullable|integer|between:0,4',
            'topic' => 'nullable|string|max:120',
            'plan' => 'nullable|string|max:120',
            'customer_name' => 'nullable|string|max:150',
            'customer_email' => 'nullable|email|max:190',
            'related_ticket_id' => 'nullable|integer|min:1',
            'privacy_accepted_at' => 'nullable|date',
            'privacy_version' => 'nullable|string|max:40',
        ]);

        $result = $tickets->register($request->attributes->get('site'), $data);

        return response()->json(['data' => $this->present($result['ticket'])], $result['created'] ? 201 : 200);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        return response()->json(['data' => $this->present($this->own($request, $ticket))]);
    }

    public function update(Request $request, TicketService $tickets, int $ticket): JsonResponse
    {
        $data = $request->validate([
            'priority' => 'nullable|integer|between:0,4',
            'status' => ['nullable', Rule::in(config('support.statuses'))],
            'topic' => 'nullable|string|max:120',
            'plan' => 'nullable|string|max:120',
        ]);

        $updated = $tickets->update($request->attributes->get('site'), $this->own($request, $ticket), array_filter($data, fn ($v) => $v !== null));

        return response()->json(['data' => $this->present($updated)]);
    }

    private function own(Request $request, int $id): Ticket
    {
        return Ticket::query()->where('site_id', $request->attributes->get('site')->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'subject' => $ticket->subject,
            'topic' => $ticket->topic,
            'plan' => $ticket->plan,
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }
}
