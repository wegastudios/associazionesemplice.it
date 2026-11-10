@extends('admin.layout')
@section('title', 'Riepilogo')
@section('content')
    <h1>Riepilogo</h1>
    <div class="card">
        <h2>Associazioni</h2>
        <p>
            Attive: {{ $orgs['active'] ?? 0 }} ·
            Sospese: {{ $orgs['suspended'] ?? 0 }} ·
            Chiuse: {{ $orgs['closed'] ?? 0 }}
        </p>
        <p class="muted">Persone nello staff: {{ $staff }}. Piani, prezzi e promozioni arrivano con l'editor del catalogo.</p>
    </div>
@endsection
