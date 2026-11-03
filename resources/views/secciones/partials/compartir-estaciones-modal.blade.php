{{-- Permisos de las estaciones sobre la sección completa (solo Jefe de Zona /
     Administrador): Ver o Editar, para todas las estaciones y/o para cada una
     (incluido el Edificio Zonal). Vale para todas las hojas, los documentos y
     las subsecciones; cada hoja puede sumar permisos propios. --}}
@php
    $nivelesSeccion = $seccion->estacionesAprobadas()->get(['estaciones.id'])->mapWithKeys(fn ($estacion) => [$estacion->id => $estacion->pivot->nivel])->all();
    $heredadaDe = collect($ancestros)->first(fn ($ancestro) => $ancestro->acceso_todas !== null || $ancestro->estacionesAprobadas()->wherePivotNotNull('nivel')->exists());
    $opcionesNivel = ['' => __('Sin acceso')] + collect(\App\Enums\NivelAccesoEstacion::cases())->mapWithKeys(fn ($nivel) => [$nivel->value => __($nivel->label())])->all();
@endphp
<div x-data="{ abierta: false }"
     @compartir-estaciones.window="abierta = true"
     @keydown.escape.window="abierta = false">
    <div x-show="abierta" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 p-0 sm:items-center sm:p-4"
         role="dialog" aria-modal="true" aria-labelledby="titulo-compartir-estaciones">
        <form method="POST" action="{{ route('secciones.estaciones.update', $seccion) }}"
              class="flex max-h-[92dvh] w-full max-w-lg flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            @csrf
            @method('PUT')
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                <h3 id="titulo-compartir-estaciones" class="font-heading text-lg font-semibold text-brand-green">{{ __('Permisos de estaciones en esta sección') }}</h3>
                <button type="button" @click="abierta = false" class="min-h-[44px] min-w-[44px] rounded-md text-gray-500 hover:bg-gray-100" aria-label="{{ __('Cerrar') }}">✕</button>
            </div>

            <div class="space-y-4 overflow-y-auto px-5 py-4 text-sm">
                @if ($heredadaDe)
                    <p class="rounded-md bg-brand-mist p-3 text-brand-green" role="status">
                        {{ __('También recibe los permisos dados en «:nombre».', ['nombre' => $heredadaDe->nombre]) }}
                    </p>
                @endif

                <p class="text-gray-600">{{ __('Ver: la estación consulta todas las hojas y documentos de la sección. Editar: además agrega, modifica y borra registros y sube o borra documentos. Aplica también a las subsecciones; cada hoja puede tener permisos adicionales.') }}</p>

                <div class="flex min-h-[44px] items-center justify-between gap-3 border-b border-gray-100 pb-3">
                    <label for="acceso-todas" class="font-medium text-gray-800">{{ __('Todas las estaciones') }}</label>
                    <select id="acceso-todas" name="todas"
                            class="min-h-[44px] rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                        @foreach ($opcionesNivel as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected(($seccion->acceso_todas?->value ?? '') === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>

                <fieldset class="space-y-1">
                    <legend class="mb-1 font-medium text-gray-800">{{ __('Por estación') }}</legend>
                    @foreach ($estaciones as $estacion)
                        <div class="flex min-h-[44px] items-center justify-between gap-3">
                            <label for="acceso-estacion-{{ $estacion->id }}" class="text-gray-700">
                                {{ $estacion->nombre }}
                                @if (array_key_exists($estacion->id, $nivelesSeccion) && $nivelesSeccion[$estacion->id] === null)
                                    {{-- Aprobación del modelo anterior (solo sus filas): no da acceso hasta elegir un nivel. --}}
                                    <span class="block text-xs text-amber-700">{{ __('Pendiente de revisar: antes veía solo sus registros. Elige Ver o Editar.') }}</span>
                                @endif
                            </label>
                            <select id="acceso-estacion-{{ $estacion->id }}" name="estaciones[{{ $estacion->id }}]"
                                    class="min-h-[44px] rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                                @foreach ($opcionesNivel as $valor => $etiqueta)
                                    <option value="{{ $valor }}" @selected(($nivelesSeccion[$estacion->id] ?? '') === $valor)>{{ $etiqueta }}</option>
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
