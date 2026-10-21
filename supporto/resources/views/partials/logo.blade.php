{{-- Logo ufficiale: orizzontale (colore / negativo nel tema scuro); sotto i 480 px solo il simbolo. Non deformare. --}}
<picture>
    <source media="(max-width: 479px) and (prefers-color-scheme: dark)" srcset="{{ route('brand', 'simbolo-negativo.svg') }}">
    <source media="(max-width: 479px)" srcset="{{ route('brand', 'simbolo-colore.svg') }}">
    <source media="(prefers-color-scheme: dark)" srcset="{{ route('brand', 'logo-orizzontale-negativo.svg') }}">
    <img src="{{ route('brand', 'logo-orizzontale-colore.svg') }}" alt="Associazione Semplice" style="height:{{ $h ?? 38 }}px;width:auto">
</picture>
