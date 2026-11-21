@extends('operator-sso.layout')
@section('title', 'Apertura del gestionale')
@section('content')
    <h1>Apriamo il gestionale</h1>
    <p>Un attimo, ti porto dentro.</p>
    <form id="sso" method="post" action="{{ route('operator.sso.enter') }}">
        @csrf
        <input type="hidden" name="t" value="">
        <button type="submit">Continua</button>
    </form>
    <p id="sso-missing" hidden>Il link non è valido o è scaduto. <a href="{{ $account }}">Torna al tuo account</a> e riapri il gestionale.</p>
    <noscript><p>Per entrare serve JavaScript. <a href="{{ $account }}">Torna al tuo account</a> e riprova da un browser che lo permetta.</p></noscript>
    <script src="{{ route('operator.sso.script', [], false) }}"></script>
@endsection
