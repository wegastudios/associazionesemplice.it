@extends('admin.layout')
@section('title', 'Registra un portale')
@section('content')
    <h1>Registra un portale</h1>
    <form method="post" action="{{ route('admin.sites.store') }}" class="card" style="max-width:720px">
        @csrf
        @include('admin.hub.sites._rules', ['site' => null])
        <p class="actions" style="margin-top:18px"><button type="submit">Registra e mostra le credenziali</button><a class="btn secondary" href="{{ route('admin.sites.index') }}">Annulla</a></p>
    </form>
@endsection
