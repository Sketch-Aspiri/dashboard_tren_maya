@props(['label', 'value', 'hint' => null, 'tono' => null, 'href' => null, 'icono' => null])

{{-- `tono` (opcional): 'alerta' o 'peligro' resaltan la cifra cuando pide atención.
     `href` (opcional) convierte la tarjeta en enlace al módulo de origen.
     `icono` (opcional): asistencia, personal, secciones, documentos. --}}
@php
    $color = ['alerta' => 'text-amber-700', 'peligro' => 'text-red-700'][$tono] ?? 'text-brand-green';
    $borde = ['alerta' => 'border-amber-300', 'peligro' => 'border-red-300'][$tono] ?? 'border-brand-green/10';
    $trazos = [
        'asistencia' => 'M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm7.7 7.7a1 1 0 00-1.4-1.4L9 11.58 7.7 10.3a1 1 0 00-1.4 1.4l2 2a1 1 0 001.4 0l4-4z',
        'personal' => 'M9 6a3 3 0 11-6 0 3 3 0 016 0zm8 0a3 3 0 11-6 0 3 3 0 016 0zm-4.07 11c.05-.33.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z',
        'secciones' => 'M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zm0 8a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zm6-6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zm2 6a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2h-2z',
        'documentos' => 'M4 4a2 2 0 012-2h4.59A2 2 0 0112 2.59L15.41 6A2 2 0 0116 7.41V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z',
    ];
    $etiqueta = $href ? 'a' : 'div';
@endphp

<{{ $etiqueta }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'group flex flex-col rounded-xl border bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-5 '.$borde.($href ? ' transition hover:border-brand-teal/40 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal' : '')]) }}>
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium text-gray-500">{{ $label }}</p>
        @if ($icono && isset($trazos[$icono]))
            <span class="dash-icono" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" clip-rule="evenodd" d="{{ $trazos[$icono] }}" /></svg>
            </span>
        @endif
    </div>
    <p class="mt-1 break-words font-heading text-2xl font-bold tabular-nums sm:text-3xl {{ $color }}">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
    @if ($href)
        <span class="mt-auto pt-3 text-xs font-semibold text-brand-teal group-hover:underline">{{ __('Ver detalle') }} <span aria-hidden="true">→</span></span>
    @endif
</{{ $etiqueta }}>
