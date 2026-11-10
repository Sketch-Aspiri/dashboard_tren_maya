@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var array<int, string> $meses */
    /** @var array<string, mixed> $resumen */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Cotizacion> $cotizaciones */
    $dinero = fn (float $monto) => '$'.number_format($monto, 2);
    $avance = $resumen['partidas'] > 0 ? round($resumen['actualizadas'] / $resumen['partidas'] * 100) : 0;
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Cotizaciones').' — '.$meses[$mes].' '.$anio"
                          :detalle="__(':a de :p partidas con precio cotizado', ['a' => $resumen['actualizadas'], 'p' => $resumen['partidas']])" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.nav activo="cotizaciones" :anio="$anio" :mes="$mes" />

            <x-insumos.avisos />

            <div class="flex flex-wrap items-end justify-between gap-3">
                <x-insumos.periodo ruta="insumos.cotizaciones.index" :anio="$anio" :mes="$mes" />
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('insumos.cotizaciones.descargar', ['anio' => $anio, 'mes' => $mes]) }}" class="ins-btn-secundario">{{ __('Descargar precios reales (Excel)') }}</a>
                    <a href="{{ route('insumos.cotizaciones.create', ['anio' => $anio, 'mes' => $mes]) }}" class="ins-btn-primario">+ {{ __('Nueva cotización') }}</a>
                </div>
            </div>

            <section class="ins-card ins-card-pad" aria-labelledby="avance-titulo">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 id="avance-titulo" class="ins-titulo">{{ __('Avance de precios del mes') }}</h3>
                    <p class="text-sm text-gray-600"><span class="font-semibold text-gray-900">{{ $avance }} %</span> {{ __('de las partidas ya tienen precio real') }}</p>
                </div>
                <div class="mt-3 flex h-2.5 w-full overflow-hidden rounded-full bg-gray-100" role="img"
                     aria-label="{{ __('Actualizadas :a, en revisión :r, sin cotización :s', ['a' => $resumen['actualizadas'], 'r' => $resumen['en_revision'], 's' => $resumen['sin_cotizacion']]) }}">
                    @if ($resumen['partidas'] > 0)
                        <div class="h-full bg-brand-teal" style="width: {{ $resumen['actualizadas'] / $resumen['partidas'] * 100 }}%"></div>
                        <div class="h-full bg-amber-400" style="width: {{ $resumen['en_revision'] / $resumen['partidas'] * 100 }}%"></div>
                    @endif
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm lg:grid-cols-4">
                    <div><dt class="flex items-center gap-1.5 ins-etiqueta"><span class="h-2 w-2 rounded-full bg-brand-teal"></span>{{ __('Actualizadas') }}</dt><dd class="mt-0.5 font-semibold text-gray-900">{{ $resumen['actualizadas'] }}</dd></div>
                    <div><dt class="flex items-center gap-1.5 ins-etiqueta"><span class="h-2 w-2 rounded-full bg-amber-400"></span>{{ __('En revisión') }}</dt><dd class="mt-0.5 font-semibold text-gray-900">{{ $resumen['en_revision'] }}</dd></div>
                    <div><dt class="flex items-center gap-1.5 ins-etiqueta"><span class="h-2 w-2 rounded-full bg-gray-300"></span>{{ __('Sin cotización') }}</dt><dd class="mt-0.5 font-semibold text-gray-900">{{ $resumen['sin_cotizacion'] }}</dd></div>
                    <div><dt class="ins-etiqueta">{{ __('Fuera del documento') }}</dt><dd class="mt-0.5 font-semibold text-gray-900">{{ $resumen['fuera_del_documento'] }}</dd></div>
                </dl>
            </section>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-kpi-card :label="__('Subtotal estimado')" :value="$dinero($resumen['subtotal_estimado'])" />
                <x-kpi-card :label="__('Subtotal con precios reales')" :value="$dinero($resumen['subtotal_real'])" />
                <x-kpi-card :label="$resumen['excede'] ? __('Excede el fondo') : __('Total con IVA')" :value="$dinero($resumen['total_con_iva'])" :tono="$resumen['excede'] ? 'peligro' : null"
                            :hint="__('Fondo rotatorio: :f', ['f' => $dinero($resumen['fondo'])])" />
            </div>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Cotizaciones del mes') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Proveedor') }}</th>
                            <th scope="col">{{ __('Folio') }}</th>
                            <th scope="col">{{ __('Fecha') }}</th>
                            <th scope="col" class="!text-right">{{ __('Renglones') }}</th>
                            <th scope="col" class="!text-right">{{ __('Total') }}</th>
                            <th scope="col">{{ __('Validación') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($cotizaciones as $cotizacion)
                            <tr>
                                <td class="fuerte">{{ $cotizacion->proveedor }}
                                    @if ($cotizacion->leida_con_ia)<span class="ins-chip-info ml-1" title="{{ __('Leída con IA') }}">IA</span>@endif
                                </td>
                                <td>{{ $cotizacion->folio }}</td>
                                <td class="whitespace-nowrap">{{ $cotizacion->fecha?->format('d/m/Y') ?? '—' }}</td>
                                <td class="num">{{ $cotizacion->renglones_count }}</td>
                                <td class="num fuerte">{{ $cotizacion->total === null ? '—' : $dinero((float) $cotizacion->total) }}</td>
                                <td><x-insumos.estado :valor="$cotizacion->validacion === 'OK' ? 'OK' : 'ALERTA'" :texto="$cotizacion->validacion === 'OK' ? __('Cuadra') : __('No cuadra')" /></td>
                                <td class="num"><a href="{{ route('insumos.cotizaciones.show', $cotizacion) }}" class="ins-btn-secundario !min-h-[36px] !px-3" aria-label="{{ __('Ver cotización :f', ['f' => $cotizacion->folio]) }}">{{ __('Ver') }}</a></td>
                            </tr>
                        @empty
                            <x-insumos.vacio columnas="7" :titulo="__('Aún no hay cotizaciones de este mes.')">{{ __('Registra la primera con «Nueva cotización»; puedes leerla del PDF con IA.') }}</x-insumos.vacio>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
