@props([
    'action',
    'etiqueta' => __('Eliminar'),
    'pregunta' => __('¿Seguro?'),
    'confirmar' => __('Sí, eliminar'),
    'metodo' => 'DELETE',
    'tono' => 'peligro',
])

@php
    $colorBoton = $tono === 'peligro' ? 'text-red-700 hover:bg-red-50 focus:ring-red-500' : 'text-gray-700 hover:bg-gray-100 focus:ring-brand-teal';
    $colorConfirmar = $tono === 'peligro' ? 'bg-red-600 hover:bg-red-700 focus:ring-red-500' : 'bg-brand-green hover:bg-brand-green-dark focus:ring-brand-teal';
@endphp

{{-- Acción con confirmación en la misma línea (sin diálogos del
     navegador): el primer clic pregunta y pasa el foco a "Cancelar" (la
     opción segura); el segundo confirma. Escape cancela. --}}
<form method="POST" action="{{ $action }}" x-data="{ confirmando: false }" {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    @csrf
    @method($metodo)
    <button type="button" x-ref="abrir" x-show="! confirmando"
            @click="confirmando = true; $nextTick(() => $refs.cancelar.focus())"
            class="min-h-[44px] rounded-md px-2 text-sm font-medium focus:outline-none focus:ring-2 {{ $colorBoton }}">
        {{ $etiqueta }}
    </button>
    <span x-show="confirmando" x-cloak role="group" aria-label="{{ $pregunta }}" class="inline-flex flex-wrap items-center gap-2"
          @keydown.escape.stop="confirmando = false; $nextTick(() => $refs.abrir.focus())">
        <span class="text-xs text-gray-800">{{ $pregunta }}</span>
        <button type="submit" class="min-h-[44px] rounded-md px-3 text-xs font-semibold text-white focus:outline-none focus:ring-2 {{ $colorConfirmar }}">{{ $confirmar }}</button>
        <button type="button" x-ref="cancelar" @click="confirmando = false; $nextTick(() => $refs.abrir.focus())"
                class="min-h-[44px] rounded-md border border-gray-300 px-3 text-xs font-semibold text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-teal">{{ __('Cancelar') }}</button>
    </span>
</form>
