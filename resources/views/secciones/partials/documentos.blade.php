@php
    $extensionesIa = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'doc', 'pptx'];
@endphp

@php
    $ordenesDocumento = [
        'recientes' => __('Más recientes primero'),
        'antiguos' => __('Más antiguos primero'),
        'nombre_asc' => __('Nombre (A → Z)'),
        'nombre_desc' => __('Nombre (Z → A)'),
        'numero_asc' => __('Número inicial (menor a mayor)'),
        'numero_desc' => __('Número inicial (mayor a menor)'),
        'tamano_desc' => __('Tamaño (mayor a menor)'),
        'tamano_asc' => __('Tamaño (menor a mayor)'),
    ];
    $hayFiltroDocumentos = $filtros['q'] || $filtros['doc_tipo'];
@endphp

@if (count($tiposDocumento) > 0)
    <form method="GET" action="{{ route('secciones.show', $seccion) }}" class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-end">
        <input type="hidden" name="tab" value="documentos">
        @if ($filtros['q']) <input type="hidden" name="q" value="{{ $filtros['q'] }}"> @endif
        @isset($parametroTabla['tabla']) <input type="hidden" name="tabla" value="{{ $parametroTabla['tabla'] }}"> @endisset
        <div class="sm:w-72">
            <label for="doc-orden" class="mb-1 block text-xs font-medium text-gray-600">{{ __('Ordenar por') }}</label>
            <select id="doc-orden" name="doc_orden" onchange="this.form.requestSubmit()"
                    class="min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                @foreach ($ordenesDocumento as $valor => $etiqueta)
                    <option value="{{ $valor }}" @selected($filtros['doc_orden'] === $valor)>{{ $etiqueta }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:w-40">
            <label for="doc-tipo" class="mb-1 block text-xs font-medium text-gray-600">{{ __('Tipo de archivo') }}</label>
            <select id="doc-tipo" name="doc_tipo" onchange="this.form.requestSubmit()"
                    class="min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                <option value="">{{ __('Todos') }}</option>
                @foreach ($tiposDocumento as $extension)
                    <option value="{{ $extension }}" @selected($filtros['doc_tipo'] === $extension)>{{ strtoupper($extension) }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="min-h-[44px] rounded-md bg-brand-green px-4 text-sm font-semibold text-white">{{ __('Aplicar') }}</button></noscript>
        @if ($filtros['doc_orden'] !== 'recientes' || $filtros['doc_tipo'])
            <a href="{{ route('secciones.show', array_filter(['seccion' => $seccion, 'tab' => 'documentos', 'q' => $filtros['q'], 'tabla' => $parametroTabla['tabla'] ?? null])) }}"
               class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Quitar orden y filtro') }}</a>
        @endif
    </form>
@endif

@if ($documentos->isEmpty())
    <div class="rounded-xl border border-dashed border-brand-green/25 bg-white p-8 text-center text-sm text-gray-600 shadow-sm shadow-brand-green/5">
        {{ $hayFiltroDocumentos ? __('Ningún documento coincide con la búsqueda o el filtro.') : __('Aún no hay documentos. Arrástralos a la zona de arriba.') }}
    </div>
@else
    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
        @foreach ($documentos as $documento)
            <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="mt-0.5 inline-flex h-9 w-12 shrink-0 items-center justify-center rounded-md bg-brand-mist text-xs font-bold uppercase text-brand-green">
                        {{ $documento->extension }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-900">{{ $documento->nombre_original }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $documento->tamanoLegible() }} · {{ $documento->created_at->format('d/m/Y H:i') }}
                            @if ($documento->autor) · {{ $documento->autor->name }} @endif
                            @if ($documento->seccion_fila_id) · {{ __('adjunto de un registro') }} @endif
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-1 sm:justify-end">
                    <button type="button"
                            x-data
                            @click="$dispatch('vista-previa', { url: @js(route('secciones.archivos.vista-previa', [$seccion, $documento])), nombre: @js($documento->nombre_original) })"
                            class="min-h-[36px] rounded-md px-2 py-1.5 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Ver') }}</button>
                    <a href="{{ route('secciones.archivos.show', [$seccion, $documento]) }}"
                       class="min-h-[36px] rounded-md px-2 py-1.5 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Descargar') }}</a>
                    @if ($puedeGestionar && $iaDisponible && ! $seccion->solo_documentos && in_array($documento->extension, $extensionesIa, true))
                        <x-confirmar-eliminar :action="route('secciones.archivos.ia', [$seccion, $documento])" metodo="POST" tono="neutro"
                                              :etiqueta="__('Extraer con IA')" :confirmar="__('Sí, enviar')"
                                              :pregunta="__('El documento completo se enviará a Claude (Anthropic) para leer sus tablas. Revisarás el resultado antes de guardarlo. ¿Continuar?')" />
                    @endif
                    @if ($puedeGestionar)
                        <x-confirmar-eliminar :action="route('secciones.archivos.destroy', [$seccion, $documento])" />
                    @endif
                </div>
            </li>
        @endforeach
    </ul>

    @include('secciones.partials.vista-previa-modal')
@endif
