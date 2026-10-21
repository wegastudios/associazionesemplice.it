@extends('site.layout')
@section('title', 'Richiesta n. '.$ticket->hub_id)
@section('content')
    <h1 style="text-transform:none">Richiesta n. {{ $ticket->hub_id }} · {{ $ticket->subject }}</h1>
    <p class="muted">Stato: <strong>{{ ['open' => 'aperto', 'pending' => 'in attesa di tua risposta', 'on_hold' => 'in lavorazione', 'solved' => 'risolto', 'closed' => 'chiuso'][$ticket->status] ?? $ticket->status }}</strong></p>
    @foreach ($messages as $m)
        <div class="card" @if ($m->author_type === 'staff') style="border-left:6px solid var(--teal)" @endif>
            <p class="muted" style="margin-top:0"><strong>{{ $m->author_type === 'staff' ? ($m->author_name ?: 'Supporto') : 'Tu' }}</strong> · {{ $m->created_at->format('d/m/Y H:i') }}</p>
            <div style="white-space:pre-line">{{ $m->body }}</div>
        </div>
    @endforeach
    @if ($ticket->isClosed() && ! $canReopen)
        <p class="card">Questa richiesta è chiusa. <a href="{{ route('ticket.create', ['rif' => $ticket->hub_id]) }}">Apri una nuova richiesta</a></p>
    @else
    <form method="post" action="{{ $replyUrl }}" class="card" style="max-width:720px">
        @csrf
        <label for="body" style="margin-top:0">{{ $ticket->isClosed() ? 'La richiesta è chiusa: scrivi per riaprirla' : 'Rispondi' }}</label>
        <textarea id="body" name="body" rows="5" maxlength="10000" required>{{ old('body') }}</textarea>
        <p class="actions" style="margin-top:14px"><button type="submit">Invia</button></p>
    </form>
    @endif
@endsection
