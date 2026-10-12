@props([
    'action',
    'etiqueta' => __('Eliminar'),
    'pregunta' => __('¿Seguro?'),
    'metodo' => 'DELETE',
])

{{-- Botón de acción destructiva con confirmación en la misma línea (sin
     diálogos del navegador): primer clic pregunta, segundo confirma. --}}
<form method="POST" action="{{ $action }}" x-data="{ confirmando: false }" {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    @csrf
    @method($metodo)
    <button type="button" x-show="! confirmando" @click="confirmando = true"
            class="min-h-[36px] rounded-md px-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500">
        {{ $etiqueta }}
    </button>
    <span x-show="confirmando" x-cloak class="inline-flex items-center gap-1">
        <span class="text-xs text-gray-700">{{ $pregunta }}</span>
        <button type="submit" class="min-h-[36px] rounded-md bg-red-600 px-2 text-xs font-semibold text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">{{ __('Sí') }}</button>
        <button type="button" @click="confirmando = false" class="min-h-[36px] rounded-md px-2 text-xs font-semibold text-gray-700 hover:bg-gray-100">{{ __('No') }}</button>
    </span>
</form>
