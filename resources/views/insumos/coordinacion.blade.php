@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var \Illuminate\Support\Collection<int, \App\Models\SolicitudInsumo> $solicitudes */
    /** @var int $aprobadas */
    /** @var array<int, string> $meses */
    $resumen = session('resumen_coordinacion');
    $listas = [
        'quitadas' => [__('Renglones que la Coordinación quitó'), 'error'],
        'agregadas' => [__('Renglones que la Coordinación agregó'), 'info'],
        'cambios' => [__('Cantidades o precios que cambió'), 'alerta'],
        'sin_solicitud' => [__('Bloques del archivo sin solicitud enviada (no se tocaron)'), 'neutro'],
        'sin_renglones' => [__('Estaciones con bloque sin renglones (no se tocaron)'), 'neutro'],
        'sin_bloque' => [__('Estaciones enviadas que no aparecen en el archivo (no se tocaron)'), 'neutro'],
    ];
    $enviadas = $solicitudes->whereIn('estado', ['enviada_coordinacion', 'devuelta'])->count();
    $devueltas = $solicitudes->where('estado', 'devuelta')->count();
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Coordinación').' — '.$meses[$mes].' '.$anio"
                          :detalle="__('Envío del ANEXO y respuesta de la Coordinación')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.nav activo="coordinacion" :anio="$anio" :mes="$mes" />

            <x-insumos.avisos />

            <x-insumos.periodo ruta="insumos.coordinacion.index" :anio="$anio" :mes="$mes" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-kpi-card :label="__('Aprobadas por enviar')" :value="$aprobadas" :tono="$aprobadas > 0 ? 'alerta' : null" :hint="__('Pasan a la Coordinación al registrar el envío.')" />
                <x-kpi-card :label="__('Enviadas a Coordinación')" :value="$enviadas" />
                <x-kpi-card :label="__('Con respuesta')" :value="$devueltas" :hint="__('La Coordinación ya devolvió su Excel.')" />
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- 1. Envío del ANEXO a la Coordinación. --}}
                <form method="POST" action="{{ route('insumos.coordinacion.enviar') }}" class="ins-card ins-card-pad flex flex-col">
                    @csrf
                    <input type="hidden" name="anio" value="{{ $anio }}">
                    <input type="hidden" name="mes" value="{{ $mes }}">
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-mist font-bold text-brand-green">1</span>
                        <h3 class="ins-titulo">{{ __('Registrar envío del ANEXO') }}</h3>
                    </div>
                    <p class="ins-ayuda mt-2">
                        {{ __('Descarga el ANEXO desde el tablero, envíalo con tu oficio y registra aquí el número. Las :n solicitud(es) aprobada(s) de este mes pasan a "Enviada a Coordinación".', ['n' => $aprobadas]) }}
                    </p>
                    <div class="mt-4">
                        <x-input-label for="oficio-coord" :value="__('Número de oficio')" />
                        <input id="oficio-coord" name="oficio" type="text" class="ins-campo mt-1" maxlength="20" required pattern="[A-Za-z0-9_\-]+" placeholder="1918">
                    </div>
                    <div class="mt-auto flex flex-wrap items-center justify-between gap-3 pt-4">
                        <a href="{{ route('insumos.index', ['anio' => $anio, 'mes' => $mes]) }}#consolidado" class="ins-enlace">{{ __('Ir a descargar el ANEXO') }}</a>
                        <button type="submit" class="ins-btn-primario" @disabled($aprobadas === 0)>{{ __('Registrar envío') }}</button>
                    </div>
                    @if ($aprobadas === 0)
                        <p class="mt-2 text-right text-xs text-gray-500">{{ __('No hay solicitudes aprobadas pendientes de enviar.') }}</p>
                    @endif
                </form>

                {{-- 2. Respuesta de la Coordinación (Excel devuelto). --}}
                <form method="POST" action="{{ route('insumos.coordinacion.respuesta') }}" enctype="multipart/form-data" class="ins-card ins-card-pad flex flex-col">
                    @csrf
                    <input type="hidden" name="anio" value="{{ $anio }}">
                    <input type="hidden" name="mes" value="{{ $mes }}">
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-mist font-bold text-brand-green">2</span>
                        <h3 class="ins-titulo">{{ __('Cargar la respuesta de la Coordinación') }}</h3>
                    </div>
                    <p class="ins-ayuda mt-2">
                        {{ __('Sube el Excel devuelto (.xlsx). Se toma la partida de la columna P.Ptal/Fin, los renglones que quitó o agregó y los cambios de cantidad o precio. Puedes volver a cargarlo si hay una versión corregida.') }}
                    </p>
                    <div class="mt-4">
                        <x-input-label for="archivo-coord" :value="__('Archivo')" />
                        <input id="archivo-coord" name="archivo" type="file" required accept=".xlsx" class="ins-archivo mt-1">
                    </div>
                    <div class="mt-auto flex justify-end pt-4">
                        <button type="submit" class="ins-btn-secundario">{{ __('Aplicar respuesta') }}</button>
                    </div>
                </form>
            </div>

            @if ($resumen)
                <section class="ins-card ins-card-pad space-y-4" aria-labelledby="resumen-coord">
                    <div>
                        <h3 id="resumen-coord" class="ins-titulo">{{ __('Resultado de la última carga') }}</h3>
                        <p class="ins-ayuda mt-1">{{ __(':s solicitud(es) actualizadas, :r renglones confirmados.', ['s' => $resumen['solicitudes'], 'r' => $resumen['actualizadas']]) }}</p>
                    </div>
                    @foreach ($listas as $clave => [$titulo, $tono])
                        @if (! empty($resumen[$clave]))
                            <details class="rounded-lg border border-gray-200" @if (in_array($clave, ['quitadas', 'agregadas', 'cambios'], true)) open @endif>
                                <summary class="flex min-h-[44px] cursor-pointer items-center gap-2 px-3 text-sm font-medium text-gray-800">
                                    <x-insumos.estado :valor="['error' => 'ERROR', 'info' => 'REVISAR', 'alerta' => 'ALERTA', 'neutro' => 'NO_VERIFICADO'][$tono]" :texto="(string) count($resumen[$clave])" />
                                    {{ $titulo }}
                                </summary>
                                <ul class="list-disc space-y-0.5 border-t border-gray-100 px-3 py-2 pl-8 text-sm text-gray-600">
                                    @foreach ($resumen[$clave] as $linea)
                                        <li>{{ $linea }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    @endforeach
                </section>
            @endif

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Solicitudes del mes y su paso por la Coordinación') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Estación') }}</th>
                            <th scope="col">{{ __('Estado') }}</th>
                            <th scope="col">{{ __('Oficio') }}</th>
                            <th scope="col">{{ __('Enviada a Coordinación') }}</th>
                            <th scope="col">{{ __('Devuelta') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($solicitudes as $solicitud)
                            <tr>
                                <td class="fuerte"><a href="{{ route('insumos.show', $solicitud) }}" class="hover:text-brand-teal hover:underline">{{ $solicitud->estacion->nombre }}</a></td>
                                <td><x-insumos.estado :valor="$solicitud->estado" :texto="__($solicitud->etiquetaEstado())" /></td>
                                <td>{{ $solicitud->oficio_numero ?? '—' }}</td>
                                <td class="whitespace-nowrap">{{ $solicitud->enviada_coordinacion_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="whitespace-nowrap">{{ $solicitud->devuelta_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <x-insumos.vacio columnas="5" :titulo="__('No hay solicitudes de este mes.')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
