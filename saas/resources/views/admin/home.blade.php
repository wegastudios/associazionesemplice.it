@extends('admin.layout')
@section('title', 'Riepilogo')
@section('content')
    <h1>Riepilogo</h1>
    <div class="card">
        <h2>Vendita</h2>
        <p>Qui si gestiscono piani, prezzi, servizi e promozioni (menu «Catalogo»). Associazioni, operatori e soci si gestiscono nell'app.</p>
        <p class="muted">Persone nello staff: {{ $staff }}.</p>
    </div>
@endsection
