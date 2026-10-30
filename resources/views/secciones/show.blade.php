@php
    $puedeGestionar = auth()->user()->can('gestionarDatos', $seccion);
    $puedeEditarSeccion = auth()->user()->can('update', $seccion);
    // "Solo documentos": sin tabla, columnas, exportación ni cargas.
    $soloDocumentos = $seccion->solo_documentos;
    $columnas = $tablaActiva->columnas;
    // Solo con varias tablas se arrastra ?tabla= (URLs limpias en secciones de una tabla).
    $parametroTabla = $tablas->count() > 1 ? ['tabla' => $tablaActiva->id] : [];
    $enlaceOrden = function (string $clave) use ($filtros): string {
        $direccion = ($filtros['sort'] === $clave && $filtros['direction'] === 'asc') ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['sort' => $clave, 'direction' => $direccion, 'page' => null, 'tab' => 'tabla']);
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <nav aria-label="{{ __('Ubicación') }}" class="flex flex-wrap items-center gap-x-1 text-xs font-medium uppercase tracking-wide text-gray-500">
            <span>{{ $seccion->grupo->nombre }}</span>
            @foreach ($ancestros as $ancestro)
                <span aria-hidden="true">›</span>
                <a href="{{ route('secciones.show', $ancestro) }}" class="hover:text-brand-teal hover:underline">{{ $ancestro->nombre }}</a>
            @endforeach
        </nav>
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green">{{ $seccion->nombre }}</h2>
    </x-slot>

    <div class="py-6 sm:py-10" x-data="{ tab: @js($tab) }">
        <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
            @include('secciones.partials.mensajes')

            @if ($cargaPendiente && $puedeGestionar && ! $soloDocumentos)
                <div class="flex flex-col gap-2 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between" role="status">
                    <span>{{ __('Tienes una carga sin aplicar: :doc.', ['doc' => $cargaPendiente->archivo?->nombre_original ?? __('documento')]) }}</span>
                    <a href="{{ route('secciones.importaciones.show', [$seccion, $cargaPendiente]) }}"
                       class="inline-flex min-h-[44px] items-center font-semibold underline">{{ __('Revisarla') }}</a>
                </div>
            @endif

            @if ($seccion->descripcion)
                <p class="text-sm text-gray-600">{{ $seccion->descripcion }}</p>
            @endif

            @include('secciones.partials.subsecciones')

            @if ($puedeGestionar)
                <x-zona-arrastre id="zona-seccion" :url="route('secciones.archivos.store', array_merge([$seccion], $parametroTabla))"
                                 :compacta="$soloDocumentos ? $documentos->isNotEmpty() : $filas->total() > 0"
                                 :titulo="$soloDocumentos ? __('Arrastra aquí tus documentos') : __('Arrastra aquí Excel, Word, PDF o imágenes')"
                                 :ayuda="$soloDocumentos
                                     ? __('Esta sección solo guarda documentos: los archivos se guardan tal cual, sin leer sus tablas.')
                                     : __('Si el archivo trae una tabla, el sistema la detecta y te muestra una vista previa antes de crear o actualizar la tabla de esta sección. Todo archivo queda guardado en Documentos.')" />
            @endif

            {{-- Barra: búsqueda + acciones --}}
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <form method="GET" action="{{ route('secciones.show', $seccion) }}" class="flex w-full gap-2 lg:max-w-md" role="search">
                    <input type="hidden" name="tab" :value="tab">
                    @isset($parametroTabla['tabla'])
                        <input type="hidden" name="tabla" value="{{ $parametroTabla['tabla'] }}">
                    @endisset
                    <label for="buscar-seccion" class="sr-only">{{ __('Buscar en esta sección') }}</label>
                    <input id="buscar-seccion" type="search" name="q" value="{{ $filtros['q'] }}"
                           placeholder="{{ $soloDocumentos ? __('Buscar en los documentos…') : __('Buscar en la tabla y en los documentos…') }}"
                           class="min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                    <button type="submit" class="min-h-[44px] rounded-md bg-brand-green px-4 text-sm font-semibold text-white hover:bg-brand-green-dark focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2">{{ __('Buscar') }}</button>
                </form>

                @unless ($soloDocumentos)
                <div class="flex flex-wrap gap-2">
                    @if ($puedeGestionar && $columnas->isNotEmpty())
                        <button type="button" x-data @click="$dispatch('fila-nueva')"
                                class="inline-flex min-h-[44px] items-center gap-2 rounded-md bg-brand-green px-4 text-xs font-semibold uppercase tracking-widest text-white hover:bg-brand-green-dark focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2">
                            + {{ __('Agregar registro') }}
                        </button>
                    @endif
                    @if ($puedeEditarSeccion)
                        <a href="{{ route('secciones.columnas.index', array_merge([$seccion], $parametroTabla)) }}"
                           class="inline-flex min-h-[44px] items-center rounded-md border border-brand-green/20 bg-white px-4 text-sm font-medium text-brand-green hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            {{ __('Columnas') }}
                        </a>
                    @endif
                    @if ($columnas->isNotEmpty())
                        <a href="{{ route('secciones.exportar', array_merge([$seccion, 'q' => $filtros['q']], $parametroTabla)) }}"
                           class="inline-flex min-h-[44px] items-center rounded-md border border-brand-green/20 bg-white px-4 text-sm font-medium text-brand-green hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            {{ __('Exportar a Excel') }}
                        </a>
                    @endif
                </div>
                @endunless
            </div>

            {{-- Pestañas --}}
            <div class="border-b border-brand-green/15" role="tablist" aria-label="{{ __('Contenido de la sección') }}">
                <nav class="-mb-px flex gap-4 overflow-x-auto">
                    @foreach ($soloDocumentos
                        ? [
                            'documentos' => __('Documentos').' ('.$documentos->count().')',
                            'enlaces' => __('Enlaces').' ('.$enlaces->count().')',
                        ]
                        : [
                            'tabla' => __('Tabla').' ('.$filas->total().')',
                            'documentos' => __('Documentos').' ('.$documentos->count().')',
                            'enlaces' => __('Enlaces').' ('.$enlaces->count().')',
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

            @unless ($soloDocumentos)
            <div x-show="tab === 'tabla'" role="tabpanel">
                {{-- Una sub-pestaña por tabla de la sección (p. ej. las hojas de un libro de Excel). --}}
                @if ($tablas->count() > 1)
                    <nav class="mb-3 flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('Tablas de la sección') }}">
                        @foreach ($tablas as $tablaItem)
                            @php $esActiva = $tablaItem->id === $tablaActiva->id; @endphp
                            <a href="{{ route('secciones.show', [$seccion, 'tabla' => $tablaItem->id, 'tab' => 'tabla', 'q' => $filtros['q']]) }}"
                               @if ($esActiva) aria-current="page" @endif
                               @class([
                                   'inline-flex min-h-[44px] items-center whitespace-nowrap rounded-full border px-4 text-sm',
                                   'border-brand-green bg-brand-green font-semibold text-white' => $esActiva,
                                   'border-brand-green/20 bg-white text-brand-green hover:bg-brand-mist' => ! $esActiva,
                               ])>{{ $tablaItem->nombre }} ({{ $tablaItem->filas_count }})</a>
                        @endforeach
                    </nav>

                    @if ($puedeEditarSeccion)
                        <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-gray-700">
                            <span>{{ __('Hoja actual: :nombre', ['nombre' => $tablaActiva->nombre]) }}</span>
                            <x-confirmar-eliminar
                                :action="route('secciones.tablas.destroy', [$seccion, $tablaActiva])"
                                :etiqueta="__('Eliminar hoja')"
                                :aria-label="__('Eliminar hoja «:nombre»', ['nombre' => $tablaActiva->nombre])"
                                :pregunta="__('¿Eliminar la hoja «:nombre» y todos sus registros?', ['nombre' => $tablaActiva->nombre])"
                            />
                        </div>
                    @endif
                @endif

                @include('secciones.partials.tabla')
            </div>
            @endunless

            <div x-show="tab === 'documentos'" x-cloak role="tabpanel">
                @include('secciones.partials.documentos')
            </div>

            <div x-show="tab === 'enlaces'" x-cloak role="tabpanel">
                @include('secciones.partials.enlaces')
            </div>

            @unless ($soloDocumentos)
            <div x-show="tab === 'cargas'" x-cloak role="tabpanel">
                @include('secciones.partials.cargas')
            </div>
            @endunless
        </div>

        @if ($puedeGestionar && $columnas->isNotEmpty() && ! $soloDocumentos)
            @include('secciones.partials.fila-modal')
        @endif
    </div>
</x-app-layout>
