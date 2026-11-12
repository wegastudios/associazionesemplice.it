@extends('admin.layout')
@section('title', 'Riepilogo')
@section('content')
    @if ($draft)
        <div class="card2" style="border-color:var(--accent)"><h2>Da pubblicare</h2><p class="muted" style="margin:0 0 10px">Ci sono modifiche al catalogo ancora in bozza: i clienti e il portale non le vedono finché non premi «Pubblica».</p><a class="btn small" href="{{ route('platform.catalog') }}">Vai al catalogo</a></div>
    @endif
    <div class="stats">
        <div class="stat"><b>{{ $counts['active'] ?? 0 }}</b><span>Abbonamenti attivi</span></div>
        <div class="stat"><b>{{ $counts['trial'] ?? 0 }}</b><span>Prove in corso</span></div>
        <div class="stat {{ $expiring > 0 ? 'warn' : '' }}"><b>{{ $expiring }}</b><span>In scadenza (30 giorni)</span></div>
        <div class="stat {{ ($counts['past_due'] ?? 0) > 0 ? 'bad' : '' }}"><b>{{ $counts['past_due'] ?? 0 }}</b><span>Pagamenti in ritardo</span></div>
        <div class="stat"><b>{{ $counts['suspended'] ?? 0 }}</b><span>Sospesi</span></div>
        <div class="stat"><b>{{ $newOrgs }}</b><span>Nuove associazioni (30 giorni)</span></div>
    </div>
    <div class="card2">
        <h2>Prossime scadenze</h2>
        @if ($recent === [])
            <p class="muted">Nessun abbonamento ricevuto dal portale per ora.</p>
        @else
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Associazione</th><th>Piano</th><th>Stato</th><th>Scade</th></tr></thead><tbody>
            @foreach ($recent as $r)
                <tr><td>{{ $r['name'] }}</td><td>{{ $r['plan'] }}</td><td><span class="badge {{ in_array($r['status'], ['past_due', 'suspended', 'cancelled'], true) ? 'bad' : '' }}">{{ $statuses[$r['status']] ?? $r['status'] }}</span></td><td>{{ $r['until'] !== '' ? \Illuminate\Support\Carbon::parse($r['until'])->format('d/m/Y') : '—' }}</td></tr>
            @endforeach
            </tbody></table></div>
        @endif
    </div>
    <div class="row-actions"><a class="btn" href="{{ route('platform.subscriptions') }}">Tutti gli abbonamenti</a><a class="btn alt" href="{{ route('platform.plans') }}">Piani e prezzi</a></div>
    <p class="muted small">Associazioni, operatori e soci si gestiscono nell'app. Persone nello staff: {{ $staff }}.</p>
@endsection
