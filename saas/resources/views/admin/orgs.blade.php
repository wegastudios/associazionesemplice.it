@extends('admin.layout')
@section('title', 'Associazioni')
@section('content')
    <h1>Associazioni</h1>
    <div class="card scroll">
        <table>
            <thead><tr><th>Nome</th><th>Indirizzo</th><th>Stato</th><th>Abbonamento</th><th>Scade</th><th>Creata</th></tr></thead>
            <tbody>
            @forelse ($orgs as $org)
                <tr>
                    <td>{{ $org->name }}</td>
                    <td>{{ $org->slug }}</td>
                    <td>{{ $org->status }}</td>
                    <td>{{ $org->subscription ?? '—' }}</td>
                    <td>{{ $org->until ?? '—' }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($org->created_at)->format('d/m/Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Nessuna associazione.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
