@extends('operator-sso.layout')
@section('title', 'Cambio di utente')
@section('content')
    <h1>Stai per cambiare utente</h1>
    <p>In questo browser sei già dentro come <strong>{{ $current['name'] }}</strong> ({{ $current['email'] }}).</p>
    <p>Se continui, esci da quell'utente ed entri come <strong>{{ $next['name'] }}</strong> ({{ $next['email'] }}).</p>
    <div class="actions">
        <form method="post" action="{{ route('operator.sso.confirm') }}">
            @csrf
            <button type="submit">Sì, cambia utente</button>
        </form>
        <a href="/gestionale">No, resto come {{ $current['name'] }}</a>
    </div>
@endsection
