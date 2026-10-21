@extends('admin.layout')
@section('title', $site->name)
@section('content')
    <h1>{{ $site->name }}</h1>
    <div class="card" style="max-width:720px">
        <dl>
            <dt>Client ID</dt><dd><code>{{ $site->client_id }}</code></dd>
            <dt>Stato</dt><dd>@if ($site->isRevoked())<span class="badge b-revoked">revocato il {{ $site->revoked_at->format('d/m/Y H:i') }}</span>@else<span class="badge b-active">attivo</span>@endif</dd>
            <dt>Ultimo uso</dt><dd>{{ $site->last_used_at?->format('d/m/Y H:i') ?? 'mai' }}</dd>
            <dt>Ticket</dt><dd><a href="{{ route('admin.tickets.index', ['site' => $site->id]) }}">{{ $site->tickets_count }}</a></dd>
        </dl>
    </div>

    <h2>Regole</h2>
    <form method="post" action="{{ route('admin.sites.update', $site) }}" class="card" style="max-width:720px">
        @csrf
        @include('admin.hub.sites._rules', ['site' => $site])
        <p class="actions" style="margin-top:18px"><button type="submit">Salva</button></p>
    </form>

    @unless ($site->isRevoked())
        <h2>Credenziali</h2>
        <div class="card actions" style="max-width:720px">
            <form method="post" action="{{ route('admin.sites.rotate', $site) }}" onsubmit="return confirm('Ruotare il segreto? Il precedente resta valido {{ config('support.secret_rotation_hours') }} ore.')">
                @csrf <button class="secondary" type="submit">Ruota il segreto</button>
            </form>
            <form method="post" action="{{ route('admin.sites.revoke', $site) }}" onsubmit="return confirm('Revocare il portale? Smette di funzionare subito.')">
                @csrf
                <input type="hidden" name="confirm" value="1">
                <button class="danger outline" type="submit">Revoca il portale</button>
            </form>
        </div>
    @endunless
@endsection
