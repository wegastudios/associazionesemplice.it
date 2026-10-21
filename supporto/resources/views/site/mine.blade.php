@extends('site.layout')
@section('title', 'Le mie richieste')
@section('content')
    <h1>Le mie richieste</h1>
    <p class="muted">{{ $customer['email'] }}</p>
    @forelse ($tickets as $row)
        @php($t = $row['ticket'])
        <div class="card">
            <p style="margin:0"><a href="{{ $row['url'] }}"><strong>Richiesta n. {{ $t->hub_id }}</strong> · {{ $t->subject }}</a></p>
            <p class="muted" style="margin:4px 0 0">{{ ['open' => 'aperto', 'pending' => 'in attesa di tua risposta', 'on_hold' => 'in lavorazione', 'solved' => 'risolto', 'closed' => 'chiuso'][$t->status] ?? $t->status }} · ultimo aggiornamento {{ ($t->last_activity_at ?? $t->created_at)->format('d/m/Y H:i') }}</p>
        </div>
    @empty
        <p>Non hai ancora aperto richieste.</p>
    @endforelse
    <p class="actions"><a class="btn" href="{{ route('ticket.create') }}">Apri una richiesta</a></p>
@endsection
