@props(['label', 'value', 'hint' => null, 'tono' => null])

{{-- `tono` (opcional): 'alerta' o 'peligro' resaltan la cifra cuando pide atención. --}}
@php
    $color = ['alerta' => 'text-amber-700', 'peligro' => 'text-red-700'][$tono] ?? 'text-brand-green';
    $borde = ['alerta' => 'border-amber-300', 'peligro' => 'border-red-300'][$tono] ?? 'border-brand-green/10';
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6 '.$borde]) }}>
    <p class="text-sm font-medium text-gray-500">{{ $label }}</p>
    <p class="mt-2 break-words font-heading text-2xl font-bold tabular-nums sm:text-3xl {{ $color }}">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</div>
