{{-- Aprobar la sección para estaciones (solo Jefe de Zona / Administrador).
     Aprobar una sección aprueba también sus subsecciones. Cada estación solo
     verá las filas y documentos etiquetados con ella. --}}
@php
    $aprobadasIds = $seccion->estacionesAprobadas()->pluck('estaciones.id')->all();
    $heredadaDe = collect($ancestros)->first(fn ($ancestro) => $ancestro->compartida_todas || $ancestro->estacionesAprobadas()->exists());
@endphp
<div x-data="{ abierta: false, todas: @js((bool) $seccion->compartida_todas) }"
     @compartir-estaciones.window="abierta = true"
     @keydown.escape.window="abierta = false">
    <div x-show="abierta" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 p-0 sm:items-center sm:p-4"
         role="dialog" aria-modal="true" aria-labelledby="titulo-compartir-estaciones">
        <form method="POST" action="{{ route('secciones.estaciones.update', $seccion) }}"
              class="flex max-h-[92dvh] w-full max-w-lg flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            @csrf
            @method('PUT')
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                <h3 id="titulo-compartir-estaciones" class="font-heading text-lg font-semibold text-brand-green">{{ __('Estaciones que ven esta sección') }}</h3>
                <button type="button" @click="abierta = false" class="min-h-[44px] min-w-[44px] rounded-md text-gray-500 hover:bg-gray-100" aria-label="{{ __('Cerrar') }}">✕</button>
            </div>

            <div class="space-y-4 overflow-y-auto px-5 py-4 text-sm">
                @if ($heredadaDe)
                    <p class="rounded-md bg-brand-mist p-3 text-brand-green" role="status">
                        {{ __('Ya está compartida vía «:nombre»: las estaciones aprobadas allí también la ven.', ['nombre' => $heredadaDe->nombre]) }}
                    </p>
                @endif

                <p class="text-gray-600">{{ __('Cada estación verá únicamente las filas y los documentos que estén etiquetados con ella. También verá las subsecciones de ésta.') }}</p>

                <label class="flex min-h-[44px] items-center gap-2 font-medium text-gray-800">
                    <input type="checkbox" name="todas" value="1" x-model="todas" class="rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                    {{ __('Todas las estaciones') }}
                </label>

                <fieldset x-bind:disabled="todas" class="space-y-1 disabled:opacity-50">
                    <legend class="sr-only">{{ __('Estaciones') }}</legend>
                    @foreach ($estaciones->where('is_operativa', true) as $estacion)
                        <label class="flex min-h-[44px] items-center gap-2 text-gray-700">
                            <input type="checkbox" name="estaciones[]" value="{{ $estacion->id }}" @checked(in_array($estacion->id, $aprobadasIds, true))
                                   class="rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                            {{ $estacion->nombre }}
                        </label>
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
