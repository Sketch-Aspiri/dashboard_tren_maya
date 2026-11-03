@props(['mapeo', 'origen'])

{{-- Cambio masivo retenido de una tabla espejo: no se aplicó hasta que el Administrador decida. --}}
@php
    $retenido = $mapeo->retenido ?? [];
    $motivo = match ($retenido['motivo'] ?? null) {
        'hoja_vacia' => __('La hoja llegó vacía o desapareció del documento.'),
        'llave_perdida' => __('La columna llave ya no viene en el Excel.'),
        default => __('Se archivarían :n de :total filas.', ['n' => $retenido['archivar'] ?? 0, 'total' => $retenido['total'] ?? 0]),
    };
@endphp
<div {{ $attributes->class(['rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900']) }} role="alert">
    <p class="font-semibold">{{ __('Cambio masivo retenido') }}: {{ $mapeo->tabla?->nombre ?? $mapeo->hoja }}</p>
    <p class="mt-1">{{ $motivo }} {{ __('La tabla conserva sus datos hasta que lo confirmes.') }}</p>
    <div class="mt-2 flex flex-wrap gap-2">
        <form method="POST" action="{{ route('secciones.origenes.mapeo.aplicar-retenido', [$origen->seccion, $origen]) }}">
            @csrf
            <input type="hidden" name="mapeo" value="{{ $mapeo->id }}">
            <button type="submit" class="min-h-[44px] rounded-md bg-amber-700 px-4 text-sm font-semibold text-white hover:bg-amber-800 focus:outline-none focus:ring-2 focus:ring-amber-500">{{ __('Aplicar') }}</button>
        </form>
        <form method="POST" action="{{ route('secciones.origenes.mapeo.ignorar-retenido', [$origen->seccion, $origen]) }}">
            @csrf
            <input type="hidden" name="mapeo" value="{{ $mapeo->id }}">
            <button type="submit" class="min-h-[44px] rounded-md border border-amber-400 bg-white px-4 text-sm font-medium text-amber-900 hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-500">{{ __('Ignorar') }}</button>
        </form>
    </div>
</div>
