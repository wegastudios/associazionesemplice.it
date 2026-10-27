@extends('admin.layout')
@section('title', 'Piani')
@section('content')
    <h1>Piani del portale</h1>
    <form method="post" action="{{ route('admin.plans.store') }}" class="card filters">
        @csrf
        <div><label for="name">Nuovo piano</label><input id="name" name="name" maxlength="120" required></div>
        <div><label for="sort">Ordine</label><input id="sort" name="sort" type="number" min="0" value="0"></div>
        <div><label><input type="checkbox" name="is_active" value="1" checked> Attivo</label></div>
        <div><button type="submit">Aggiungi</button></div>
    </form>
    @foreach ($plans as $plan)
        <div class="card actions">
            <form method="post" action="{{ route('admin.plans.update', $plan) }}" class="actions">
                @csrf
                <input name="name" value="{{ $plan->name }}" maxlength="120" required aria-label="Nome piano">
                <input name="sort" type="number" min="0" value="{{ $plan->sort }}" aria-label="Ordine" style="width:90px">
                <label style="margin:0"><input type="checkbox" name="is_active" value="1" @checked($plan->is_active)> Attivo</label>
                <button class="secondary" type="submit">Salva</button>
            </form>
            <form method="post" action="{{ route('admin.plans.destroy', $plan) }}" onsubmit="return confirm('Eliminare il piano?')">@csrf <button class="danger outline" type="submit">Elimina</button></form>
        </div>
    @endforeach
@endsection
