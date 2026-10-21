<?php

namespace App\Services\Site;

use App\Exceptions\HubRejectedException;
use App\Exceptions\HubUnavailableException;
use App\Models\SupportTicket;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Client dell'hub centrale: registrazione dei ticket (id), regole del portale, aggiornamenti. */
class HubClient
{
    public function isConfigured(): bool
    {
        return config('support.hub.url') !== '' && filled(config('support.hub.token'));
    }

    /**
     * Regole del portale (priorita consentite, riapertura). In cache per pochi minuti.
     *
     * @return array{allowed_priorities: list<int>, allow_reopen: bool, priority_labels: array<int|string, string>, statuses: list<string>}
     */
    public function rules(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('support.hub.rules');
        }

        return Cache::remember('support.hub.rules', 300, function () {
            $data = $this->send('get', '/api/v1/rules')->json('data');

            return is_array($data) ? $data : throw new HubUnavailableException('Risposta dell\'hub non valida.');
        });
    }

    /** Priorita consentite, o null se l'hub non e raggiungibile. @return list<int>|null */
    public function allowedPriorities(): ?array
    {
        try {
            return array_map('intval', $this->rules()['allowed_priorities']);
        } catch (HubUnavailableException|HubRejectedException) {
            return null;
        }
    }

    /** Registra il ticket e restituisce l'id assegnato dall'hub. */
    public function register(SupportTicket $ticket): int
    {
        $response = $this->send('post', '/api/v1/tickets', array_filter([
            'request_uuid' => $ticket->request_uuid,
            'subject' => $ticket->subject,
            'priority' => $ticket->priority,
            'topic' => $ticket->topic?->name,
            'plan' => $ticket->plan?->name,
            'customer_name' => $ticket->customer_name,
            'customer_email' => $ticket->customer_email,
            'related_ticket_id' => $ticket->related_hub_id,
            'privacy_accepted_at' => $ticket->privacy_accepted_at?->toIso8601String(),
            'privacy_version' => $ticket->privacy_version,
        ], fn ($v) => $v !== null));

        $id = $response->json('data.id');

        return is_int($id) ? $id : throw new HubUnavailableException('Risposta dell\'hub senza id del ticket.');
    }

    /**
     * Aggiorna sull'hub priorita, stato, argomento o piano.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(SupportTicket $ticket, array $data): void
    {
        if (! $ticket->isRegistered()) {
            return;
        }

        $this->send('patch', '/api/v1/tickets/'.$ticket->hub_id, $data);
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $method, string $path, array $payload = []): Response
    {
        if (! $this->isConfigured()) {
            throw new HubUnavailableException('Collegamento all\'hub non configurato (SUPPORT_HUB_URL, SUPPORT_HUB_TOKEN).');
        }

        try {
            /** @var Response $response */
            $response = $this->http()->{$method}(config('support.hub.url').$path, $payload);
        } catch (ConnectionException $e) {
            throw new HubUnavailableException('Hub non raggiungibile.', 0, $e);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new HubUnavailableException('Hub non disponibile (HTTP '.$response->status().').');
        }

        if ($response->failed()) {
            $error = $response->json('error') ?? [];

            throw new HubRejectedException(
                (string) ($error['code'] ?? 'HUB_ERROR'),
                (string) ($error['message'] ?? 'Richiesta rifiutata dall\'hub.'),
                $response->status(),
                is_array($error['allowed_priorities'] ?? null) ? array_map('intval', $error['allowed_priorities']) : null,
            );
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('support.hub.token'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('support.hub.timeout'));
    }
}
