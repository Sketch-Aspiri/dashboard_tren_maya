@if ($fila->archivos->isNotEmpty())
    <span class="me-1 inline-flex items-center rounded-full bg-brand-mist px-2 py-0.5 text-xs text-brand-green" title="{{ $fila->archivos->pluck('nombre_original')->implode(', ') }}">
        📎 {{ $fila->archivos->count() }}
    </span>
@endif
@php
    // Una estación solo edita sus filas y nunca borra; la zona hace ambas cosas.
    $puedeEditarFila = auth()->user()->can('actualizarFila', [$seccion, $fila]);
    $puedeBorrarFila = auth()->user()->can('eliminarFila', [$seccion, $fila]);
@endphp
@if ($puedeEditarFila)
    @php
        $valoresFormulario = $columnas->mapWithKeys(function ($columna) use ($fila) {
            $valor = $fila->datos[$columna->clave] ?? null;

            return [$columna->clave => is_bool($valor) ? ($valor ? '1' : '0') : $valor];
        });
    @endphp
    <button type="button" x-data
            @click="$dispatch('fila-editar', { id: {{ $fila->id }}, valores: @js($valoresFormulario), estacion: @js($fila->estacion_id) })"
            class="min-h-[36px] rounded-md px-2 text-sm font-medium text-brand-teal hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
        {{ __('Editar') }}
    </button>
@endif
@if ($puedeBorrarFila)
    <x-confirmar-eliminar :action="route('secciones.filas.destroy', [$seccion, $fila])" />
@endif
