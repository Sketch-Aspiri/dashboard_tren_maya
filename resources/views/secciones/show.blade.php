@php
    $puedeGestionar = auth()->user()->can('gestionarDatos', $seccion);
    $puedeEditarSeccion = auth()->user()->can('update', $seccion);
    $columnas = $seccion->columnas;
    $enlaceOrden = function (string $clave) use ($filtros): string {
        $direccion = ($filtros['sort'] === $clave && $filtros['direction'] === 'asc') ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['sort' => $clave, 'direction' => $direccion, 'page' => null, 'tab' => 'tabla']);
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $seccion->grupo->nombre }}</p>
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green">{{ $seccion->nombre }}</h2>
    </x-slot>

    <div class="py-6 sm:py-10" x-data="{ tab: @js($tab) }">
        <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
            @include('secciones.partials.mensajes')

            @if ($cargaPendiente && $puedeGestionar)
                <div class="flex flex-col gap-2 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between" role="status">
                    <span>{{ __('Tienes una carga sin aplicar: :doc.', ['doc' => $cargaPendiente->archivo?->nombre_original ?? __('documento')]) }}</span>
                    <a href="{{ route('secciones.importaciones.show', [$seccion, $cargaPendiente]) }}"
                       class="inline-flex min-h-[44px] items-center font-semibold underline">{{ __('Revisarla') }}</a>
                </div>
            @endif

            @if ($seccion->descripcion)
                <p class="text-sm text-gray-600">{{ $seccion->descripcion }}</p>
            @endif

            @if ($puedeGestionar)
                <x-zona-arrastre id="zona-seccion" :url="route('secciones.archivos.store', $seccion)"
                                 :compacta="$filas->total() > 0"
                                 :titulo="__('Arrastra aquí Excel, Word, PDF o imágenes')"
                                 :ayuda="__('Si el archivo trae una tabla, el sistema la detecta y te muestra una vista previa antes de crear o actualizar la tabla de esta sección. Todo archivo queda guardado en Documentos.')" />
            @endif

            {{-- Barra: búsqueda + acciones --}}
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <form method="GET" action="{{ route('secciones.show', $seccion) }}" class="flex w-full gap-2 lg:max-w-md" role="search">
                    <input type="hidden" name="tab" :value="tab">
                    <label for="buscar-seccion" class="sr-only">{{ __('Buscar en esta sección') }}</label>
                    <input id="buscar-seccion" type="search" name="q" value="{{ $filtros['q'] }}"
                           placeholder="{{ __('Buscar en la tabla y en los documentos…') }}"
                           class="min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                    <button type="submit" class="min-h-[44px] rounded-md bg-brand-green px-4 text-sm font-semibold text-white hover:bg-brand-green-dark focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2">{{ __('Buscar') }}</button>
                </form>

                <div class="flex flex-wrap gap-2">
                    @if ($puedeGestionar && $columnas->isNotEmpty())
                        <button type="button" x-data @click="$dispatch('fila-nueva')"
                                class="inline-flex min-h-[44px] items-center gap-2 rounded-md bg-brand-green px-4 text-xs font-semibold uppercase tracking-widest text-white hover:bg-brand-green-dark focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2">
                            + {{ __('Agregar registro') }}
                        </button>
                    @endif
                    @if ($puedeEditarSeccion)
                        <a href="{{ route('secciones.columnas.index', $seccion) }}"
                           class="inline-flex min-h-[44px] items-center rounded-md border border-brand-green/20 bg-white px-4 text-sm font-medium text-brand-green hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            {{ __('Columnas') }}
                        </a>
                    @endif
                    @if ($columnas->isNotEmpty())
                        <a href="{{ route('secciones.exportar', [$seccion, 'q' => $filtros['q']]) }}"
                           class="inline-flex min-h-[44px] items-center rounded-md border border-brand-green/20 bg-white px-4 text-sm font-medium text-brand-green hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            {{ __('Exportar a Excel') }}
                        </a>
                    @endif
                </div>
            </div>

            {{-- Pestañas --}}
            <div class="border-b border-brand-green/15" role="tablist" aria-label="{{ __('Contenido de la sección') }}">
                <nav class="-mb-px flex gap-4 overflow-x-auto">
                    @foreach ([
                        'tabla' => __('Tabla').' ('.$filas->total().')',
                        'documentos' => __('Documentos').' ('.$documentos->count().')',
                        'cargas' => __('Cargas').' ('.$cargas->count().')',
                    ] as $clave => $etiqueta)
                        <button type="button" role="tab" @click="tab = @js($clave)" :aria-selected="(tab === @js($clave)).toString()"
                                :class="tab === @js($clave) ? 'border-brand-teal text-brand-green font-semibold' : 'border-transparent text-gray-600 hover:text-brand-green'"
                                class="min-h-[44px] whitespace-nowrap border-b-4 px-1 text-sm">
                            {{ $etiqueta }}
                        </button>
                    @endforeach
                </nav>
            </div>

            <div x-show="tab === 'tabla'" role="tabpanel">
                @include('secciones.partials.tabla')
            </div>

            <div x-show="tab === 'documentos'" x-cloak role="tabpanel">
                @include('secciones.partials.documentos')
            </div>

            <div x-show="tab === 'cargas'" x-cloak role="tabpanel">
                @include('secciones.partials.cargas')
            </div>
        </div>

        @if ($puedeGestionar && $columnas->isNotEmpty())
            @include('secciones.partials.fila-modal')
        @endif
    </div>
</x-app-layout>
