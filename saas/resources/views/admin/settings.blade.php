@extends('admin.layout')
@section('title', 'Impostazioni')
@section('content')
    <h1>Impostazioni</h1>
    @if (session('status'))<p class="card" role="status">{{ session('status') }}</p>@endif
    @if ($problem)<p class="card error" role="alert">{{ $problem }}</p>@endif

    <form method="post" action="{{ route('platform.settings.save') }}" class="card">
        @csrf
        <h2>Dominio personalizzato</h2>
        <p class="muted">Il cliente delega il suo dominio ai nostri nameserver; poi il dominio si associa in cPanel all'associazione e il certificato lo emette AutoSSL. Questi sono i nameserver che il cliente vede nelle istruzioni. Lasciando un campo vuoto vale il valore del file d'ambiente del server.</p>
        @foreach ($fields as $key => $field)
            @php $name = 'settings['.str_replace('.', '_', $key).']'; $id = 's-'.str_replace('.', '-', $key); @endphp
            <label for="{{ $id }}">{{ $field['label'] }}</label>
            <input id="{{ $id }}" name="{{ $name }}" value="{{ $field['value'] }}" @disabled(! $canEdit)>
        @endforeach
        @if ($canEdit)
            <button class="primary" type="submit">Salva</button>
        @else
            <p class="muted">Solo l'amministratore può cambiare le impostazioni (sola lettura).</p>
        @endif
    </form>
@endsection
