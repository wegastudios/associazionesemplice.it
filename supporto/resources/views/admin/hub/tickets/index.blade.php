@extends('admin.layout')
@section('title', 'Casella')
@section('content')
    <h1>Casella unica</h1>
    <form method="get" class="card filters">
        <div>
            <label for="site">Portale</label>
            <select id="site" name="site"><option value="">Tutti</option>@foreach ($sites as $s)<option value="{{ $s->id }}" @selected(($filters['site'] ?? '') === $s->id)>{{ $s->name }}</option>@endforeach</select>
        </div>
        <div>
            <label for="status">Stato</label>
            <select id="status" name="status"><option value="">Tutti</option>@foreach (config('support.statuses') as $st)<option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ $st }}</option>@endforeach</select>
        </div>
        <div>
            <label for="priority">Priorità</label>
            <select id="priority" name="priority"><option value="">Tutte</option>@foreach ($labels as $level => $label)<option value="{{ $level }}" @selected(isset($filters['priority']) && (string) $filters['priority'] === (string) $level)>{{ $level }} · {{ $label }}</option>@endforeach</select>
        </div>
        <div>
            <label for="q">Cerca (id, oggetto, email)</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}">
        </div>
        <div><button type="submit">Filtra</button></div>
    </form>
    <div class="card tablewrap">
        <table>
            <thead><tr><th>Ticket</th><th>Portale</th><th>Oggetto</th><th>Priorità</th><th>Stato</th><th>Ultima attività</th></tr></thead>
            <tbody>
            @forelse ($tickets as $t)
                <tr>
                    <td><a href="{{ route('admin.tickets.show', $t->id) }}">{{ $t->id }}</a></td>
                    <td>{{ $t->site->name }}</td>
                    <td>{{ $t->subject }}</td>
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
