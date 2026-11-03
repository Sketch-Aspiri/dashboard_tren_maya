{{-- Navegador de carpetas de los documentos sincronizados desde la nube.
     Solo aparece si hay subcarpetas o ya se está dentro de una. La búsqueda
     de texto completo recorre todas las carpetas. --}}
@php
    $parametrosCarpeta = fn (?string $ruta) => array_filter([
        'seccion' => $seccion,
        'tab' => 'documentos',
        'carpeta' => $ruta ?: null,
        'doc_orden' => $filtros['doc_orden'] !== 'recientes' ? $filtros['doc_orden'] : null,
        'doc_tipo' => $filtros['doc_tipo'],
        'tabla' => $parametroTabla['tabla'] ?? null,
    ]);
@endphp

@if ($carpetaActual !== '' || $subcarpetas !== [])
    <nav class="mb-3" aria-label="{{ __('Carpetas de la nube') }}">
        <ol class="flex flex-wrap items-center gap-1 text-sm">
            <li>
                <a href="{{ route('secciones.show', $parametrosCarpeta(null)) }}"
                   class="inline-flex min-h-[44px] items-center rounded-md px-2 font-medium text-brand-teal hover:bg-brand-mist">{{ __('Inicio') }}</a>
            </li>
            @foreach ($migas as $miga)
                <li class="flex items-center gap-1">
                    <span aria-hidden="true" class="text-gray-400">/</span>
                    @if ($loop->last)
                        <span class="px-2 font-semibold text-gray-900" aria-current="page">{{ $miga['nombre'] }}</span>
                    @else
                        <a href="{{ route('secciones.show', $parametrosCarpeta($miga['ruta'])) }}"
                           class="inline-flex min-h-[44px] items-center rounded-md px-2 font-medium text-brand-teal hover:bg-brand-mist">{{ $miga['nombre'] }}</a>
                    @endif
                </li>
            @endforeach
        </ol>

        @if ($subcarpetas !== [])
            <ul class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($subcarpetas as $subcarpeta)
                    <li>
                        <a href="{{ route('secciones.show', $parametrosCarpeta($subcarpeta['ruta'])) }}"
                           class="flex min-h-[44px] items-center justify-between gap-2 rounded-lg border border-brand-green/15 bg-white px-3 py-2 text-sm hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            <span class="truncate font-medium text-gray-900">{{ $subcarpeta['nombre'] }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ trans_choice('{1} :count documento|[0,*] :count documentos', $subcarpeta['total']) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </nav>
@endif
