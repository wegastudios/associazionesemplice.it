@extends('admin.layout')
@section('title', 'Ticket')
@section('content')
    <h1>Ticket</h1>
    @if ($pendingCount > 0)
        <div class="errors" role="status">{{ $pendingCount }} ticket in attesa di registrazione presso l'hub (non hanno ancora un numero). <a href="{{ route('admin.tickets.index', ['pending' => 1]) }}">Mostra</a></div>
    @endif
    <form method="get" class="card filters">
        <div>
            <label for="status">Stato</label>
            <select id="status" name="status"><option value="">Tutti</option>@foreach (config('support.statuses') as $st)<option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ $st }}</option>@endforeach</select>
        </div>
        <div>
            <label for="priority">Priorità</label>
            <select id="priority" name="priority"><option value="">Tutte</option>@foreach ($labels as $level => $label)<option value="{{ $level }}" @selected(isset($filters['priority']) && (string) $filters['priority'] === (string) $level)>{{ $level }} · {{ $label }}</option>@endforeach</select>
        </div>
        <div>
            <label for="q">Cerca (numero, oggetto, email)</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}">
        </div>
        <div><button type="submit">Filtra</button></div>
    </form>
    <div class="card tablewrap">
        <table>
            <thead><tr><th>Numero</th><th>Oggetto</th><th>Cliente</th><th>Priorità</th><th>Stato</th><th>Ultima attività</th></tr></thead>
            <tbody>
            @forelse ($tickets as $t)
                <tr>
                    <td><a href="{{ route('admin.tickets.show', $t) }}">{{ $t->hub_id ?? 'in attesa' }}</a></td>
                    <td>{{ $t->subject }}</td>
                    <td>{{ $t->customer_name }}<br><span class="muted">{{ $t->customer_email }}</span></td>
                    <td>{{ $t->priority }} · {{ $labels[$t->priority] ?? '' }}</td>
                    <td>{{ $t->status }}</td>
                    <td>{{ $t->last_activity_at?->format('d/m/Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Nessun ticket.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $tickets->links() }}
@endsection
