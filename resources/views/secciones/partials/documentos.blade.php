@php
    $extensionesIa = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'doc', 'pptx'];
@endphp

@if ($documentos->isEmpty())
    <div class="rounded-xl border border-dashed border-brand-green/25 bg-white p-8 text-center text-sm text-gray-600 shadow-sm shadow-brand-green/5">
        {{ $filtros['q'] ? __('Ningún documento coincide con la búsqueda.') : __('Aún no hay documentos. Arrástralos a la zona de arriba.') }}
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
                    @if ($puedeGestionar && $iaDisponible && in_array($documento->extension, $extensionesIa, true))
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
