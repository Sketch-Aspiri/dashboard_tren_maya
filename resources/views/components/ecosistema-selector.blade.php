@props(['variante' => 'barra'])

{{-- Selector de sistema del ecosistema Zona Oriente (Dashboard | Tickets).
     El mismo componente existe en Tickets (resources/views/components/ecosistema-selector.blade.php):
     si cambia aquí, cambia allá (docs/ECOSISTEMA.md). --}}
@php
    $actual = config('ecosistema.sistema');
    $sistemas = [
        'dashboard' => ['texto' => __('Dashboard'), 'url' => config('ecosistema.urls.dashboard').'/dashboard',
            'trazo' => 'M3 4a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm8 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V4zM3 13a1 1 0 011-1h5a1 1 0 011 1v3a1 1 0 01-1 1H4a1 1 0 01-1-1v-3zm8-3a1 1 0 011-1h4a1 1 0 011 1v6a1 1 0 01-1 1h-4a1 1 0 01-1-1v-6z'],
        'tickets' => ['texto' => __('Tickets'), 'url' => config('ecosistema.urls.tickets').'/dashboard',
            'trazo' => 'M2 6a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 000 4v2a2 2 0 01-2 2H4a2 2 0 01-2-2v-2a2 2 0 000-4V6zm9 0a1 1 0 10-2 0v1a1 1 0 102 0V6zm0 4a1 1 0 10-2 0v1a1 1 0 102 0v-1zm0 4a1 1 0 10-2 0v1a1 1 0 102 0v-1z'],
    ];
@endphp

<nav aria-label="{{ __('Sistemas de la Zona Oriente') }}"
     {{ $attributes->merge(['class' => 'inline-flex rounded-lg border border-brand-green/15 bg-white p-0.5']) }}>
    @foreach ($sistemas as $clave => $sistema)
        <a href="{{ $sistema['url'] }}" @if ($clave === $actual) aria-current="page" @endif
           class="{{ $clave === $actual ? 'bg-brand-green text-white' : 'text-gray-600 hover:bg-brand-mist hover:text-brand-green' }} inline-flex min-h-[40px] items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="{{ $sistema['trazo'] }}" /></svg>
            {{ $sistema['texto'] }}
        </a>
    @endforeach
</nav>
