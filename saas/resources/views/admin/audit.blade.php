@extends('admin.layout')
@section('title', 'Registro')
@section('content')
    <h1>Registro delle azioni</h1>
    <div class="card scroll">
        <table>
            <thead><tr><th>Quando</th><th>Chi</th><th>Azione</th><th>Oggetto</th><th>Indirizzo IP</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row->created_at)->timezone('Europe/Rome')->format('d/m/Y H:i') }}</td>
                    <td>{{ $row->email ?? '—' }}</td>
                    <td>{{ $row->action }}</td>
                    <td>{{ $row->subject ?? '—' }}</td>
                    <td>{{ $row->ip ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Ancora nessuna azione.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
