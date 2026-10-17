@extends('site.layout')
@section('title', 'Ticket '.$ticket->hub_id)
@section('content')
    <h1 style="text-transform:none">Ticket {{ $ticket->hub_id }} · {{ $ticket->subject }}</h1>
    <p class="muted">Stato: <strong>{{ ['open' => 'aperto', 'pending' => 'in attesa di tua risposta', 'on_hold' => 'in lavorazione', 'solved' => 'risolto', 'closed' => 'chiuso'][$ticket->status] ?? $ticket->status }}</strong></p>
    @foreach ($messages as $m)
        <div class="card" @if ($m->author_type === 'staff') style="border-left:6px solid var(--teal)" @endif>
            <p class="muted" style="margin-top:0"><strong>{{ $m->author_type === 'staff' ? ($m->author_name ?: 'Assistenza') : 'Tu' }}</strong> · {{ $m->created_at->format('d/m/Y H:i') }}</p>
            <div style="white-space:pre-line">{{ $m->body }}</div>
        </div>
    @endforeach
    <form method="post" action="{{ $replyUrl }}" class="card" style="max-width:720px">
        @csrf
        <label for="body" style="margin-top:0">{{ $ticket->isClosed() ? 'Il ticket è chiuso: scrivi per riaprirlo' : 'Rispondi' }}</label>
        <textarea id="body" name="body" rows="5" maxlength="10000" required>{{ old('body') }}</textarea>
        <p class="actions" style="margin-top:14px"><button type="submit">Invia</button></p>
    </form>
@endsection
