@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var array<int, string> $meses */
    /** @var array<string, mixed> $resumen */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Factura> $facturas */
    $dinero = fn (float $monto) => '$'.number_format($monto, 2);
    $resultados = session('resultados_facturas', []);
    $porEstado = $resumen['facturas_por_estado'];
    $conProblemas = $porEstado['ERROR'] + $porEstado['REVISAR'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Facturas').' — '.$meses[$mes].' '.$anio"
                          :detalle="trans_choice(':n factura cargada|:n facturas cargadas', $resumen['facturas'], ['n' => $resumen['facturas']])" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.nav activo="facturas" :anio="$anio" :mes="$mes" />

            <x-insumos.avisos />

            @if ($resultados !== [])
                <section class="ins-card ins-card-pad" aria-labelledby="carga-titulo">
                    <h3 id="carga-titulo" class="ins-titulo">{{ __('Resultado de la última carga') }}</h3>
                    <ul class="mt-3 divide-y divide-gray-100 text-sm">
                        @foreach ($resultados as $r)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                <span class="{{ $r['ok'] ? 'ins-chip-ok' : 'ins-chip-error' }}">{{ $r['ok'] ? __('Cargada') : __('No cargada') }}</span>
                                <span class="font-medium text-gray-900">{{ $r['archivo'] }}</span>
                                <span class="text-gray-600">{{ $r['mensaje'] }}</span>
                                @if ($r['ok'])<a href="{{ route('insumos.facturas.show', $r['factura_id']) }}" class="ins-enlace !min-h-0 sm:ml-auto">{{ __('Ver') }}</a>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <div class="flex flex-wrap items-end justify-between gap-3">
                <x-insumos.periodo ruta="insumos.facturas.index" :anio="$anio" :mes="$mes" />
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('insumos.facturas.descargar', ['anio' => $anio, 'mes' => $mes, 'tipo' => 'compra']) }}" class="ins-btn-secundario">{{ __('Compra de insumos (Excel)') }}</a>
                    <a href="{{ route('insumos.facturas.descargar', ['anio' => $anio, 'mes' => $mes, 'tipo' => 'reporte']) }}" class="ins-btn-secundario">{{ __('Reporte de comprobación (Excel)') }}</a>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-kpi-card :label="__('Facturas cargadas')" :value="$resumen['facturas']" :tono="$porEstado['ERROR'] > 0 ? 'peligro' : ($conProblemas > 0 ? 'alerta' : null)"
                            :hint="__('OK :o · Alerta :a · Revisar :r · Error :e', ['o' => $porEstado['OK'], 'a' => $porEstado['ALERTA'], 'r' => $porEstado['REVISAR'], 'e' => $porEstado['ERROR']])" />
                <x-kpi-card :label="$resumen['excede'] ? __('Excede el fondo') : __('Total facturado')" :value="$dinero($resumen['total'])" :tono="$resumen['excede'] ? 'peligro' : null"
                            :hint="__('Fondo rotatorio: :f', ['f' => $dinero($resumen['fondo'])])" />
                <x-kpi-card :label="__('Pendiente por ejercer')" :value="$dinero($resumen['pendiente'])" :tono="$resumen['pendiente'] < 0 ? 'peligro' : null" />
                <x-kpi-card :label="__('No facturado (sin IVA)')" :value="$dinero($resumen['no_facturado'])" :hint="__('Pedido o cotizado y no comprado.')" />
            </div>

            {{-- Carga: muestra qué XML tiene su PDF antes de subir, para no descubrirlo después. --}}
            <form method="POST" action="{{ route('insumos.facturas.store') }}" enctype="multipart/form-data" class="ins-card ins-card-pad"
                  x-data="{ xml: [], pdf: [], base(n) { return n.replace(/\.[^.]+$/, '').toLowerCase() },
                            tomar(e, cual) { this[cual] = Array.from(e.target.files).map(f => f.name) },
                            tienePdf(n) { return (this.xml.length === 1 && this.pdf.length === 1) || this.pdf.some(p => this.base(p) === this.base(n)) },
                            faltan() { return this.xml.filter(n => ! this.tienePdf(n)).length } }">
                @csrf
                <input type="hidden" name="anio" value="{{ $anio }}">
                <input type="hidden" name="mes" value="{{ $mes }}">
                <h3 class="ins-titulo">{{ __('Cargar facturas (XML CFDI)') }}</h3>
                <p class="ins-ayuda mt-1">{{ __('Elige uno o varios XML. Cada uno se compara con su cotización y con las partidas de :mes; el PDF de la factura es obligatorio y debe llamarse igual que su XML (factura1.xml y factura1.pdf).', ['mes' => $meses[$mes]]) }}</p>
                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1fr_auto] lg:items-end">
                    <div>
                        <x-input-label for="xml-fac" :value="__('XML (CFDI)')" />
                        <input id="xml-fac" name="xml[]" type="file" multiple required accept=".xml" class="ins-archivo mt-1" @change="tomar($event, 'xml')">
                    </div>
                    <div>
                        <x-input-label for="pdf-fac" :value="__('PDF de cada factura')" />
                        <input id="pdf-fac" name="pdf[]" type="file" multiple required accept=".pdf,application/pdf" class="ins-archivo mt-1" @change="tomar($event, 'pdf')">
                    </div>
                    <button type="submit" class="ins-btn-primario">{{ __('Cargar') }}</button>
                </div>
                <div x-show="xml.length > 0" x-cloak class="mt-4" aria-live="polite">
                    <p class="text-sm font-medium" :class="faltan() > 0 ? 'text-amber-800' : 'text-green-800'"
                       x-text="faltan() > 0 ? faltan() + ' {{ __('XML sin su PDF: no se cargarán hasta que agregues el PDF con el mismo nombre.') }}' : '{{ __('Cada XML tiene su PDF.') }}'"></p>
                    <ul class="mt-2 flex flex-wrap gap-2">
                        <template x-for="n in xml" :key="n">
                            <li :class="tienePdf(n) ? 'ins-chip-ok' : 'ins-chip-alerta'" x-text="n"></li>
                        </template>
                    </ul>
                </div>
            </form>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Facturas del mes') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Factura') }}</th>
                            <th scope="col">{{ __('Emisor') }}</th>
                            <th scope="col">{{ __('Estación') }}</th>
                            <th scope="col">{{ __('Fecha') }}</th>
                            <th scope="col">{{ __('Cotización') }}</th>
                            <th scope="col" class="!text-right">{{ __('Total') }}</th>
                            <th scope="col">{{ __('Comprobación') }}</th>
                            <th scope="col">{{ __('SAT') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($facturas as $factura)
                            <tr>
                                <td class="fuerte whitespace-nowrap">{{ $factura->referencia() }}</td>
                                <td>{{ $factura->emisor_nombre }}</td>
                                <td>{{ $factura->estacion?->nombre ?? '—' }}</td>
                                <td class="whitespace-nowrap">{{ $factura->fecha->format('d/m/Y') }}</td>
                                <td>{{ $factura->cotizacion ? $factura->cotizacion->proveedor.' '.$factura->cotizacion->folio : '—' }}</td>
                                <td class="num fuerte">{{ $dinero((float) $factura->total) }}</td>
                                <td><x-insumos.estado :valor="$factura->estado" /></td>
                                <td>
                                    @if ($factura->verificacion_sat === 'CANCELADO')
                                        <span class="ins-chip-error">{{ __('Cancelada en el SAT') }}</span>
                                    @else
                                        <x-insumos.estado :valor="$factura->verificacion_sat" :texto="['VIGENTE' => __('Vigente'), 'NO_ENCONTRADO' => __('No encontrada'), 'NO_VERIFICADO' => __('Sin verificar'), 'ERROR' => __('Sin respuesta')][$factura->verificacion_sat] ?? $factura->verificacion_sat" />
                                    @endif
                                </td>
                                <td class="num"><a href="{{ route('insumos.facturas.show', $factura) }}" class="ins-btn-secundario !min-h-[36px] !px-3" aria-label="{{ __('Ver factura :f', ['f' => $factura->referencia()]) }}">{{ __('Ver') }}</a></td>
                            </tr>
                        @empty
                            <x-insumos.vacio columnas="9" :titulo="__('Aún no hay facturas de este mes.')">{{ __('Carga arriba los XML con su PDF para empezar la comprobación.') }}</x-insumos.vacio>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <section x-data="comprobacionMes(@js($firmantesComprobacion), @js(route('insumos.facturas.comprobacion.mes')))" class="ins-card ins-card-pad">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="ins-titulo">{{ __('Comprobación del mes (PDF)') }}</h3>
                        <p class="ins-ayuda mt-1">{{ __('Junta en un solo PDF la comprobación de las :n facturas de :mes (cada una: factura, validación, factura y validación del XML). Los cargos, nombres y firmas se aplican a todas.', ['n' => $facturas->count(), 'mes' => $meses[$mes]]) }}</p>
                    </div>
                    <button type="button" @click="abierto = ! abierto" :aria-expanded="abierto.toString()" aria-controls="comprobacion-mes" class="ins-btn-secundario"
                            x-text="abierto ? '{{ __('Ocultar') }}' : '{{ __('Preparar PDF') }}'"></button>
                </div>
                @error('comprobacion')<p class="ins-aviso-error mt-3" role="alert">{{ $message }}</p>@enderror
                <form id="comprobacion-mes" x-show="abierto" x-cloak x-ref="forma" method="POST" action="{{ route('insumos.facturas.comprobacion.mes') }}" enctype="multipart/form-data" class="mt-4 space-y-4 border-t border-gray-100 pt-4">
                    @csrf
                    <input type="hidden" name="anio" value="{{ $anio }}">
                    <input type="hidden" name="mes" value="{{ $mes }}">
                    @include('insumos.facturas._firmantes', ['prefijo' => 'cm'])
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="verPdf()" :disabled="cargando" class="ins-btn-secundario"
                                x-text="cargando ? '{{ __('Generando…') }}' : (pdfUrl ? '{{ __('Actualizar vista previa') }}' : '{{ __('Vista previa del PDF') }}')"></button>
                        <button type="submit" class="ins-btn-primario">{{ __('Descargar PDF') }}</button>
                    </div>
                    <p x-show="error" x-cloak x-text="error" class="ins-aviso-error" role="alert"></p>
                    <iframe x-show="pdfUrl" x-cloak :src="pdfUrl" title="{{ __('Comprobación del mes en PDF') }}" class="h-[80vh] w-full rounded-md border border-gray-300 bg-white"></iframe>
                </form>
            </section>

            @include('insumos.facturas._comprobacion-scripts')
        </div>
    </div>
</x-app-layout>
