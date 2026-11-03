{{-- Permisos de las estaciones sobre la hoja en pantalla (solo Jefe de Zona /
     Administrador). Suman a los de la sección: el nivel efectivo es el mayor
     de los dos. Con permiso solo sobre la hoja, la estación ve únicamente esa
     hoja de la sección (no sus documentos sueltos). --}}
@php
    $nivelesHoja = $tablaActiva->estacionesAprobadas()->get(['estaciones.id'])->mapWithKeys(fn ($estacion) => [$estacion->id => $estacion->pivot->nivel])->all();
    $nivelesDeSeccion = $seccion->estacionesAprobadas()->get(['estaciones.id'])->mapWithKeys(fn ($estacion) => [$estacion->id => $estacion->pivot->nivel])->all();
    $opcionesHoja = ['' => __('Sin permiso propio')] + collect(\App\Enums\NivelAccesoEstacion::cases())->mapWithKeys(fn ($nivel) => [$nivel->value => __($nivel->label())])->all();
@endphp
<div x-data="{ abierta: false }"
     @compartir-hoja.window="abierta = true"
     @keydown.escape.window="abierta = false">
    <div x-show="abierta" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 p-0 sm:items-center sm:p-4"
         role="dialog" aria-modal="true" aria-labelledby="titulo-compartir-hoja">
        <form method="POST" action="{{ route('secciones.tablas.acceso.update', [$seccion, $tablaActiva]) }}"
              class="flex max-h-[92dvh] w-full max-w-lg flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            @csrf
            @method('PUT')
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                <h3 id="titulo-compartir-hoja" class="min-w-0 break-words font-heading text-lg font-semibold text-brand-green">{{ __('Permisos de estaciones en la hoja «:hoja»', ['hoja' => $tablaActiva->nombre]) }}</h3>
                <button type="button" @click="abierta = false" class="min-h-[44px] min-w-[44px] rounded-md text-gray-500 hover:bg-gray-100" aria-label="{{ __('Cerrar') }}">✕</button>
            </div>

            <div class="space-y-4 overflow-y-auto px-5 py-4 text-sm">
                <p class="text-gray-600">{{ __('Ver: la estación consulta todos los registros de esta hoja. Editar: además los agrega, modifica y borra. Se suman a los permisos de la sección.') }}</p>

                <fieldset class="space-y-1">
                    <legend class="sr-only">{{ __('Estaciones') }}</legend>
                    @foreach ($estaciones as $estacion)
                        @php
                            $deSeccion = \App\Enums\NivelAccesoEstacion::mayor(\App\Enums\NivelAccesoEstacion::tryFrom((string) ($nivelesDeSeccion[$estacion->id] ?? '')), $seccion->acceso_todas);
                        @endphp
                        <div class="flex min-h-[44px] items-center justify-between gap-3">
                            <label for="acceso-hoja-{{ $estacion->id }}" class="text-gray-700">
                                {{ $estacion->nombre }}
                                @if ($deSeccion)
                                    <span class="block text-xs text-gray-500">{{ __('Por la sección: :nivel', ['nivel' => __($deSeccion->label())]) }}</span>
                                @endif
                            </label>
                            <select id="acceso-hoja-{{ $estacion->id }}" name="estaciones[{{ $estacion->id }}]"
                                    class="min-h-[44px] rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                                @foreach ($opcionesHoja as $valor => $etiqueta)
                                    <option value="{{ $valor }}" @selected(($nivelesHoja[$estacion->id] ?? '') === $valor)>{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </fieldset>
            </div>

            <div class="flex justify-end gap-2 border-t border-gray-100 px-5 py-4">
                <button type="button" @click="abierta = false" class="min-h-[44px] rounded-md px-4 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Cancelar') }}</button>
                <x-primary-button>{{ __('Guardar') }}</x-primary-button>
            </div>
        </form>
    </div>
</div>
