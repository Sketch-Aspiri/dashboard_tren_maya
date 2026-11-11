@php
    /** @var \App\Models\Factura $factura */
    /** @var array<int, string> $meses */
    /** @var \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, \App\Models\FacturaHallazgo>> $hallazgosPorConcepto */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Cotizacion> $cotizaciones */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Estacion> $estaciones */
    /** @var \Illuminate\Support\Collection<int, array{id: int, etiqueta: string}> $partidas */
    $dinero = fn (?float $monto) => $monto === null ? '—' : '$'.number_format($monto, 2);
    $generales = $hallazgosPorConcepto->get(0, collect());
    $textoSat = ['VIGENTE' => __('Vigente en el SAT'), 'CANCELADO' => __('Cancelada en el SAT'), 'NO_ENCONTRADO' => __('No encontrada en el SAT'), 'NO_VERIFICADO' => __('Sin verificar en el SAT'), 'ERROR' => __('El SAT no respondió')];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Factura').' '.$factura->referencia()" :detalle="$factura->emisor_nombre"
                          :volver="route('insumos.facturas.index', ['anio' => $factura->anio, 'mes' => $factura->mes])"
                          :volver-texto="__('Facturas de :mes :anio', ['mes' => $meses[$factura->mes], 'anio' => $factura->anio])" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.nav activo="facturas" :anio="$factura->anio" :mes="$factura->mes" />

            <x-insumos.avisos />

            <x-seguimiento-tickets :modelo="$factura" />

            @if ($factura->verificacion_sat === 'CANCELADO')
                <div class="ins-aviso-error" role="alert">
                    <span aria-hidden="true" class="mt-0.5 font-bold">!</span>
                    <p><span class="font-semibold">{{ __('El SAT reporta esta factura como cancelada.') }}</span> {{ __('No debe comprobarse ni pagarse; su comprobación en PDF está bloqueada.') }}</p>
                </div>
            @endif

            {{-- Ficha: estado, datos fiscales y acciones sobre los archivos. --}}
            <section class="ins-card ins-card-pad">
                <div class="flex flex-wrap items-center gap-2">
                    <x-insumos.estado :valor="$factura->estado" :texto="__('Comprobación: :e', ['e' => $factura->estado])" />
                    <x-insumos.estado :valor="$factura->verificacion_sat" :texto="$textoSat[$factura->verificacion_sat] ?? $factura->verificacion_sat" />
                    @if ($factura->sat_es_cancelable)
                        <span class="text-xs text-gray-500">{{ $factura->sat_es_cancelable }}@if ($factura->sat_estatus_cancelacion && $factura->sat_estatus_cancelacion !== 'Sin cancelación en proceso') · {{ $factura->sat_estatus_cancelacion }}@endif</span>
                    @endif
                    @if ($factura->sat_consultado_at)
                        <span class="text-xs text-gray-500">· {{ __('consultado el :f', ['f' => $factura->sat_consultado_at->format('d/m/Y H:i')]) }}</span>
                    @endif
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div class="sm:col-span-2"><dt class="ins-etiqueta">{{ __('Folio fiscal (UUID)') }}</dt><dd class="mt-0.5 break-all font-mono text-xs text-gray-900">{{ $factura->uuid }}</dd></div>
                    <div><dt class="ins-etiqueta">{{ __('Fecha') }}</dt><dd class="mt-0.5 text-gray-900">{{ $factura->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="ins-etiqueta">{{ __('Estación') }}</dt><dd class="mt-0.5 text-gray-900">{{ $factura->estacion?->nombre ?? '—' }}</dd></div>
                    <div><dt class="ins-etiqueta">{{ __('Emisor') }}</dt><dd class="mt-0.5 font-mono text-xs text-gray-900">{{ $factura->emisor_rfc }}</dd></div>
                    <div><dt class="ins-etiqueta">{{ __('Receptor') }}</dt><dd class="mt-0.5 font-mono text-xs text-gray-900">{{ $factura->receptor_rfc }}</dd></div>
                </dl>

                <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                    <form method="POST" action="{{ route('insumos.facturas.verificar-sat', $factura) }}" x-data="{ enviando: false }" @submit="enviando = true">
                        @csrf
                        <button type="submit" class="ins-btn-secundario" :disabled="enviando" x-text="enviando ? '{{ __('Consultando al SAT…') }}' : '{{ __('Verificar en el SAT') }}'"></button>
                    </form>
                    <a href="{{ route('insumos.facturas.archivo', [$factura, 'xml']) }}" class="ins-btn-neutro">{{ __('Descargar XML') }}</a>
                    @if ($factura->pdf_ruta)
                        <a href="{{ route('insumos.facturas.archivo', [$factura, 'pdf']) }}" target="_blank" rel="noopener" class="ins-btn-neutro">{{ __('Ver PDF') }}</a>
                    @endif
                    <x-confirmar-eliminar class="sm:ml-auto" :action="route('insumos.facturas.destroy', $factura)" :etiqueta="__('Eliminar factura')"
                                          :pregunta="__('¿Eliminar esta factura y sus archivos?')" />
                </div>
            </section>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                <x-kpi-card :label="__('Subtotal')" :value="$dinero((float) $factura->subtotal)" />
                <x-kpi-card :label="__('IVA')" :value="$dinero((float) $factura->iva)" />
                <x-kpi-card :label="__('ISR retenido')" :value="$dinero((float) $factura->isr_retenido)" />
                <x-kpi-card :label="__('Total')" :value="$dinero((float) $factura->total)" />
                <x-kpi-card class="col-span-2 lg:col-span-1" :label="__('Cotización')" :value="$factura->cotizacion ? $factura->cotizacion->folio : '—'"
                            :hint="$factura->vinculo_cotizacion ? __('Vinculada por :como', ['como' => $factura->vinculo_cotizacion]) : ($factura->referencia_cot ? __('Notas: COT-:n', ['n' => $factura->referencia_cot]) : __('Sin cotización vinculada'))" />
            </div>

            @if ($generales->isNotEmpty())
                <section class="ins-card ins-card-pad" aria-labelledby="hallazgos-titulo">
                    <h3 id="hallazgos-titulo" class="ins-titulo">{{ __('Hallazgos de la factura') }}</h3>
                    <ul class="mt-3 divide-y divide-gray-100 text-sm">
                        @foreach ($generales as $h)
                            <li class="flex flex-wrap items-start gap-x-3 gap-y-1 py-2">
                                <x-insumos.estado :valor="$h->tipo" />
                                <span class="text-gray-800">{{ $h->detalle }}</span>
                                <span class="font-mono text-xs text-gray-400 sm:ml-auto">{{ $h->codigo }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section aria-labelledby="conceptos-titulo" class="space-y-3">
                <h3 id="conceptos-titulo" class="ins-titulo">{{ __('Conceptos facturados') }}</h3>
                <div class="ins-tabla-marco">
                    <table class="ins-tabla">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('N°') }}</th>
                                <th scope="col">{{ __('Concepto') }}</th>
                                <th scope="col" class="!text-right">{{ __('Cant.') }}</th>
                                <th scope="col" class="!text-right">{{ __('P.U.') }}</th>
                                <th scope="col" class="!text-right">{{ __('Importe') }}</th>
                                <th scope="col" class="!text-right">{{ __('IVA') }}</th>
                                <th scope="col">{{ __('Partida solicitada') }}</th>
                                <th scope="col">{{ __('Hallazgos') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($factura->conceptos as $concepto)
                                @php $hallazgos = $hallazgosPorConcepto->get($concepto->id, collect()); @endphp
                                <tr class="{{ $hallazgos->contains(fn ($h) => $h->tipo === 'ERROR') ? '!bg-red-50/60' : '' }}">
                                    <td>{{ $concepto->numero }}</td>
                                    <td class="min-w-[14rem]"><span class="fuerte">{{ $concepto->descripcion }}</span>@if ($concepto->no_identificacion)<span class="block font-mono text-xs text-gray-500">{{ $concepto->no_identificacion }}</span>@endif</td>
                                    <td class="num">{{ (float) $concepto->cantidad }}</td>
                                    <td class="num">{{ $dinero((float) $concepto->valor_unitario) }}</td>
                                    <td class="num fuerte">{{ $dinero((float) $concepto->importe) }}</td>
                                    <td class="num">{{ $concepto->iva_tasa === null ? '—' : (float) $concepto->iva_tasa.' %' }}</td>
                                    <td class="min-w-[16rem]">
                                        <form method="POST" action="{{ route('insumos.facturas.concepto', $concepto) }}">
                                            @csrf
                                            @method('PUT')
                                            <select name="partida_id" class="ins-campo" onchange="this.form.submit()" aria-label="{{ __('Partida solicitada del concepto :n', ['n' => $concepto->numero]) }}">
                                                <option value="">{{ __('— Automático —') }}</option>
                                                @foreach ($partidas as $partida)
                                                    <option value="{{ $partida['id'] }}" @selected($concepto->solicitud_insumo_partida_id === $partida['id'])>{{ $partida['etiqueta'] }}</option>
                                                @endforeach
                                            </select>
                                            <noscript><button type="submit" class="ins-enlace">{{ __('Guardar') }}</button></noscript>
                                        </form>
                                        @if ($concepto->vinculo)<p class="mt-1 text-xs text-gray-500">{{ __('Vinculado por :v', ['v' => $concepto->vinculo]) }}</p>@endif
                                    </td>
                                    <td class="min-w-[14rem]">
                                        @forelse ($hallazgos as $h)
                                            <p class="mb-1.5 flex flex-wrap items-start gap-1.5" title="{{ $h->codigo }}"><x-insumos.estado :valor="$h->tipo" /> <span class="text-xs text-gray-700">{{ $h->detalle }}</span></p>
                                        @empty
                                            <span class="text-xs text-gray-400">—</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @include('insumos.facturas._comprobacion', ['factura' => $factura, 'vista' => $vistaComprobacion])

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <form method="POST" action="{{ route('insumos.facturas.update', $factura) }}" class="ins-card ins-card-pad">
                    @csrf
                    @method('PUT')
                    <h3 class="ins-titulo">{{ __('Cotización y estación') }}</h3>
                    <p class="ins-ayuda mt-1">{{ __('Corrige el vínculo si el sistema no lo encontró; al guardar se vuelve a analizar la factura.') }}</p>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="cotizacion_id" :value="__('Cotización')" />
                            <select id="cotizacion_id" name="cotizacion_id" class="ins-campo mt-1">
                                <option value="">{{ __('— Buscar automáticamente —') }}</option>
                                @foreach ($cotizaciones as $cotizacion)
                                    <option value="{{ $cotizacion->id }}" @selected($factura->cotizacion_id === $cotizacion->id)>{{ $cotizacion->proveedor }} · {{ $cotizacion->folio }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="estacion_id" :value="__('Estación')" />
                            <select id="estacion_id" name="estacion_id" class="ins-campo mt-1">
                                <option value="">{{ __('— Deducir de las partidas —') }}</option>
                                @foreach ($estaciones as $estacion)
                                    <option value="{{ $estacion->id }}" @selected($factura->estacion_id === $estacion->id)>{{ $estacion->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end"><button type="submit" class="ins-btn-secundario">{{ __('Guardar y analizar de nuevo') }}</button></div>
                </form>

                <form method="POST" action="{{ route('insumos.facturas.pdf', $factura) }}" enctype="multipart/form-data" class="ins-card ins-card-pad">
                    @csrf
                    <h3 class="ins-titulo">{{ $factura->pdf_ruta ? __('Reemplazar el PDF') : __('Subir el PDF') }}</h3>
                    <p class="ins-ayuda mt-1">{{ $factura->pdf_ruta ? __('Ya hay un PDF guardado (:n). Sube otro solo si estaba mal.', ['n' => $factura->pdf_nombre]) : __('Sin el PDF no se puede generar la comprobación.') }}</p>
                    <label for="pdf-respaldo" class="sr-only">{{ __('PDF de la factura') }}</label>
                    <input id="pdf-respaldo" name="archivo" type="file" required accept=".pdf" class="ins-archivo mt-4">
                    <div class="mt-4 flex justify-end"><button type="submit" class="ins-btn-secundario">{{ __('Guardar PDF') }}</button></div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
