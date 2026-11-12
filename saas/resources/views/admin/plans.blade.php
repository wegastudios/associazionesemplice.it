@extends('admin.layout')
@section('title', 'Piani')
@section('content')
    <h1>Piani</h1>
    @if (session('status'))<p class="card" role="status">{{ session('status') }}</p>@endif
    <p class="muted">Un piano già in vigore non si modifica: ogni modifica crea la versione successiva, così chi l'ha già comprato non cambia. Le modifiche stanno nella bozza finché non premi «Pubblica» nel <a href="{{ route('platform.catalog') }}">catalogo</a>.@if ($hasDraft) <strong>C'è una bozza non pubblicata.</strong>@endif</p>

    @foreach ($plans as $code => $versions)
        <div class="card">
            <h2>{{ end($versions)['name'] }} <span class="tag">{{ $code }}</span></h2>
            <p class="muted">{{ end($versions)['audience'] }}</p>
            <div class="scroll">
                <table>
                    <thead><tr><th>Versione</th><th>Stato</th><th>Visibile</th><th>Assistenza</th><th>Prova</th></tr></thead>
                    <tbody>
                    @foreach ($versions as $v)
                        <tr>
                            <td>v{{ $v['plan_version'] }}</td>
                            <td>{{ $v['in_vigore'] ? 'in vigore' : 'solo in bozza' }}</td>
                            <td>{{ $v['visible'] ? 'sì' : 'no' }}</td>
                            <td>{{ ['free' => 'Gratuita', 'paid' => 'Standard', 'top' => 'Top'][$v['support_level']] ?? $v['support_level'] }}</td>
                            <td>{{ $v['trial_days'] }} giorni</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p><a href="{{ route('platform.plans.edit', $code) }}">{{ auth('platform')->user()?->canWrite() ? 'Modifica' : 'Apri' }}</a></p>
        </div>
    @endforeach

    @if (auth('platform')->user()?->canWrite())
        <p><a href="{{ route('platform.plans.new') }}">Aggiungi un piano</a></p>
    @endif
@endsection
