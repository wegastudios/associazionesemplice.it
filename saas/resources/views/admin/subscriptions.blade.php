@extends('admin.layout')
@section('title', 'Abbonamenti')
@section('content')
    <h1>Abbonamenti</h1>
    <p class="muted">Quello che il portale ha comunicato al SaaS. Soci, operatori e dati delle associazioni non si vedono qui: stanno nell'app.</p>

    <div class="card">
        <p>
            @foreach ($statuses as $code => $label)
                <a href="?stato={{ $code }}"><span class="tag">{{ $label }}: {{ $counts[$code] ?? 0 }}</span></a>
            @endforeach
            <a href="?"><span class="tag">Tutti</span></a>
        </p>
        <form method="get" class="grid">
            <div><label for="q">Cerca (nome o riferimento)</label><input id="q" name="q" value="{{ $filters['q'] }}"></div>
            <div>
                <label for="stato">Stato</label>
                <select id="stato" name="stato">
                    <option value="">Tutti</option>
                    @foreach ($statuses as $code => $label)<option value="{{ $code }}" @selected($filters['stato'] === $code)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div><label for="piano">Piano (codice)</label><input id="piano" name="piano" value="{{ $filters['piano'] }}"></div>
            <div><button class="primary" type="submit">Filtra</button></div>
        </form>
    </div>

    <div class="card scroll">
        <table>
            <thead><tr><th>Associazione</th><th>Piano</th><th>Stato</th><th>Fino al</th><th>Soci</th><th>Pacchetti</th><th>Promo</th><th>Prezzo/mese</th></tr></thead>
            <tbody>
            @forelse ($subscriptions as $s)
                <tr>
                    <td>{{ $s['name'] }}<br><span class="muted">{{ $s['ref'] }}</span></td>
                    <td>{{ $s['plan'] }}@if ($s['plan_version']) <span class="tag">v{{ $s['plan_version'] }}</span>@endif</td>
                    <td>{{ $statuses[$s['status']] ?? $s['status'] }}</td>
                    <td>{{ $s['until'] }}</td>
                    <td>{{ $s['members'] ?? '—' }}</td>
                    <td>{{ $s['addons'] === [] ? '—' : implode(', ', $s['addons']) }}</td>
                    <td>{{ $s['promo'] ?? '—' }}</td>
                    <td>{{ $s['price'] !== null ? $s['price'].' €' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Nessun abbonamento.</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="muted">{{ $total }} risultati · pagina {{ $page }} di {{ $pages }}
            @if ($page > 1) · <a href="?{{ http_build_query(array_filter($filters) + ['pagina' => $page - 1]) }}">Precedente</a>@endif
            @if ($page < $pages) · <a href="?{{ http_build_query(array_filter($filters) + ['pagina' => $page + 1]) }}">Successiva</a>@endif
        </p>
    </div>
@endsection
