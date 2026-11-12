@extends('admin.layout')
@section('title', 'Pacchetti e servizi')
@section('content')
    <h1>Pacchetti e servizi</h1>
    @if (session('status'))<p class="card" role="status">{{ session('status') }}</p>@endif
    <p class="muted">Quello che si può aggiungere a un piano: pacchetti di funzioni, operatori, spazio, dominio, database dedicato. Le modifiche stanno nella bozza finché non premi «Pubblica» nel <a href="{{ route('platform.catalog') }}">catalogo</a>.@if ($hasDraft) <strong>C'è una bozza non pubblicata.</strong>@endif</p>

    <div class="card scroll">
        <table>
            <thead><tr><th>Nome</th><th>Tipo</th><th>Prezzo</th><th>Stato</th><th>Visibile</th><th></th></tr></thead>
            <tbody>
            @foreach ($addons as $a)
                <tr>
                    <td>{{ $a['name'] }} <span class="tag">{{ $a['code'] }}</span></td>
                    <td>{{ ['package' => 'Pacchetto', 'operators' => 'Operatori', 'storage' => 'Spazio', 'domain' => 'Dominio', 'dedicated_db' => 'Database dedicato', 'recharge' => 'Ricarica'][$a['kind']] ?? $a['kind'] }}</td>
                    <td>{{ $a['riepilogo'] }}</td>
                    <td>{{ $a['in_vigore'] ? 'in vigore' : 'solo in bozza' }}</td>
                    <td>{{ $a['visible'] ? 'sì' : 'no' }}</td>
                    <td><a href="{{ route('platform.addons.edit', $a['code']) }}">{{ auth('platform')->user()?->canWrite() ? 'Modifica' : 'Apri' }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if (auth('platform')->user()?->canWrite())
        <p><a href="{{ route('platform.addons.new') }}">Aggiungi un pacchetto o servizio</a></p>
    @endif
@endsection
