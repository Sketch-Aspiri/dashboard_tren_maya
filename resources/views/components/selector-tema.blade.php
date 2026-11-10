@props(['variante' => 'icono'])

{{-- Selector de tema (Alpine.data('selectorTema') en resources/js/tema.js).
     "icono": un botón que alterna sistema → claro → oscuro (barra de escritorio).
     "segmentos": las tres opciones a la vista (menú de celular). --}}
@php
    $iconos = [
        'sistema' => 'M3 5a2 2 0 012-2h10a2 2 0 012 2v7a2 2 0 01-2 2h-3v2h2a1 1 0 110 2H6a1 1 0 110-2h2v-2H5a2 2 0 01-2-2V5zm2 0v7h10V5H5z',
        'claro' => 'M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.46 4.95l.7.7a1 1 0 001.42-1.41l-.71-.71a1 1 0 00-1.41 1.42zM16 10a1 1 0 011-1h1a1 1 0 110 2h-1a1 1 0 01-1-1zm-1.76-5.66a1 1 0 010 1.42l-.7.7a1 1 0 11-1.42-1.41l.71-.71a1 1 0 011.41 0zM10 16a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.46a1 1 0 001.41-1.41l-.7-.71a1 1 0 00-1.42 1.41l.71.71zm1.41 8.49l-.7.7a1 1 0 01-1.42-1.41l.71-.71a1 1 0 011.41 1.42zM4 10a1 1 0 01-1 1H2a1 1 0 110-2h1a1 1 0 011 1z',
        'oscuro' => 'M17.29 13.29A8 8 0 016.71 2.71a8.003 8.003 0 1010.58 10.58z',
    ];
    $etiquetas = ['sistema' => __('Sistema'), 'claro' => __('Claro'), 'oscuro' => __('Oscuro')];
@endphp

@if ($variante === 'segmentos')
    <div x-data="selectorTema" {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3']) }}>
        <span class="text-sm font-medium text-gray-700">{{ __('Tema') }}</span>
        <div class="inline-flex rounded-lg border border-brand-green/15 bg-white p-1" role="radiogroup" aria-label="{{ __('Tema') }}">
            @foreach ($etiquetas as $valor => $texto)
                <button type="button" role="radio" @click="elegir('{{ $valor }}')" :aria-checked="(tema === '{{ $valor }}').toString()"
                        :class="tema === '{{ $valor }}' ? 'bg-brand-green text-white' : 'text-gray-600 hover:bg-brand-mist'"
                        class="inline-flex min-h-[36px] items-center gap-1.5 rounded-md px-2.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="{{ $iconos[$valor] }}" /></svg>
                    {{ $texto }}
                </button>
            @endforeach
        </div>
    </div>
@else
    <button type="button" x-data="selectorTema" @click="siguiente()" :title="etiqueta" :aria-label="etiqueta"
            {{ $attributes->merge(['class' => 'inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-md border border-brand-green/15 text-brand-green transition hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal']) }}>
        @foreach ($iconos as $valor => $trazo)
            <svg x-show="tema === '{{ $valor }}'" @if ($valor !== 'sistema') x-cloak @endif class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="{{ $trazo }}" /></svg>
        @endforeach
    </button>
@endif
