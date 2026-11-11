@extends('admin.layout')
@section('title', 'Accesso')
@push('head')
    <meta http-equiv="refresh" content="0;url={{ $home }}">
@endpush
@section('content')
    <div class="card">
        <h1>Accesso effettuato</h1>
        <p><a href="{{ $home }}">Apri l'amministrazione</a></p>
    </div>
@endsection
