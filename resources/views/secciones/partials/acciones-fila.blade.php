@php
    // Los botones se distinguen por el número de registro; el primer dato los describe (aria-describedby).
    $nombreFila = __('registro :n', ['n' => $numeroFila ?? $fila->id]);
@endphp
@if ($fila->archivos->isNotEmpty())
    <span class="me-1 inline-flex items-center rounded-full bg-brand-mist px-2 py-0.5 text-xs text-brand-green">
        <span aria-hidden="true">📎 {{ $fila->archivos->count() }}</span>
        <span class="sr-only">{{ __('Adjuntos') }}: {{ $fila->archivos->pluck('nombre_original')->implode(', ') }}</span>
    </span>
@endif
@php
    // Una estación solo edita sus filas y nunca borra; la zona hace ambas cosas.
    // Las filas de una tabla espejo son de solo lectura (no se consulta la política fila por fila).
    $puedeEditarFila = ! ($esEspejo ?? false) && auth()->user()->can('actualizarFila', [$seccion, $fila]);
    $puedeBorrarFila = ! ($esEspejo ?? false) && auth()->user()->can('eliminarFila', [$seccion, $fila]);
@endphp
@if ($puedeEditarFila)
    @php
        $valoresFormulario = $columnas->mapWithKeys(function ($columna) use ($fila) {
            $valor = $fila->datos[$columna->clave] ?? null;

            return [$columna->clave => is_bool($valor) ? ($valor ? '1' : '0') : $valor];
        });
    @endphp
    <button type="button" x-data
            @isset($idDato) aria-describedby="{{ $idDato }}" @endisset
            @click="$dispatch('fila-editar', { id: {{ $fila->id }}, valores: @js($valoresFormulario), estacion: @js($fila->estacion_id) })"
            class="min-h-[36px] rounded-md px-2 text-sm font-medium text-brand-teal hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
        {{ __('Editar') }}<span class="sr-only"> {{ $nombreFila }}</span>
    </button>
@endif
@if ($puedeBorrarFila)
    <x-confirmar-eliminar :action="route('secciones.filas.destroy', [$seccion, $fila])" :aria-label="__('Eliminar :r', ['r' => $nombreFila])" />
@endif
