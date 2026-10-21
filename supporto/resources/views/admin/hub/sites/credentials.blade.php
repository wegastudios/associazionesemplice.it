@extends('admin.layout')
@section('title', 'Credenziali')
@section('content')
    <h1>{{ $rotated ? 'Nuovo segreto' : 'Portale registrato' }} · {{ $site->name }}</h1>
    <div class="card" style="max-width:820px">
        <p><strong>Conservale ora: non verranno più mostrate.</strong> Vanno nel file <code>.env</code> del sito di supporto del portale.</p>
        <p class="muted" style="margin-bottom:0">Token di accesso (<code>SUPPORT_HUB_TOKEN</code>)</p>
        <div class="key">{{ $token }}</div>
        @if ($hmac)
            <p class="muted" style="margin-bottom:0">Segreto per la firma degli eventi (fase successiva)</p>
            <div class="key">{{ $hmac }}</div>
        @endif
        @if ($rotated)
            <p class="hint">Il segreto precedente resta valido per {{ config('support.secret_rotation_hours') }} ore, il tempo di aggiornare il sito.</p>
        @endif
        <p class="actions"><a class="btn" href="{{ route('admin.sites.show', $site) }}">Ho salvato le credenziali</a></p>
    </div>
@endsection
