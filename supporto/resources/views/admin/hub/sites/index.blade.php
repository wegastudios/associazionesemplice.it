@extends('admin.layout')
@section('title', 'Portali')
@section('content')
    <h1>Portali di supporto</h1>
    <p class="actions"><a class="btn" href="{{ route('admin.sites.create') }}">Registra un portale</a></p>
    <div class="card tablewrap">
        <table>
            <thead><tr><th>Nome</th><th>Credenziali</th><th>Priorità</th><th>Riapertura</th><th>Ticket</th><th>Ultimo uso</th></tr></thead>
            <tbody>
            @forelse ($sites as $site)
                <tr>
                    <td><a href="{{ route('admin.sites.show', $site) }}">{{ $site->name }}</a></td>
                    <td><code>{{ $site->client_id }}</code> @if ($site->isRevoked())<span class="badge b-revoked">revocato</span>@else<span class="badge b-active">attivo</span>@endif</td>
                    <td>{{ implode(', ', $site->priorities()) }}</td>
                    <td>{{ $site->allow_reopen ? 'sì' : 'no' }}</td>
                    <td>{{ $site->tickets_count }}</td>
                    <td>{{ $site->last_used_at?->diffForHumans() ?? 'mai' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Nessun portale registrato.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
