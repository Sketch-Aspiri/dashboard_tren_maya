@php
    /** @var \App\Models\Factura $factura */
    /** @var array<int, string> $meses */
    /** @var \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, \App\Models\FacturaHallazgo>> $hallazgosPorConcepto */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Cotizacion> $cotizaciones */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Estacion> $estaciones */
    /** @var \Illuminate\Support\Collection<int, array{id: int, etiqueta: string}> $partidas */
    $dinero = fn (?float $monto) => $monto === null ? '—' : '$'.number_format($monto, 2);
    $campo = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal text-sm';
    $estilos = ['OK' => 'bg-green-100 text-green-800', 'ALERTA' => 'bg-amber-100 text-amber-800', 'REVISAR' => 'bg-blue-100 text-blue-800', 'ERROR' => 'bg-red-100 text-red-800'];
    $generales = $hallazgosPorConcepto->get(0, collect());
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Factura') }} {{ $factura->referencia() }} — {{ $factura->emisor_nombre }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-xl border border-brand-green/10 bg-brand-mist p-4 text-sm text-brand-green">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3 rounded-xl border border-brand-green/10 bg-white p-4 text-sm text-gray-700 shadow-sm shadow-brand-green/5">
                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $estilos[$factura->estado] ?? '' }}">{{ $factura->estado }}</span>
                <span>UUID: <span class="font-mono text-xs">{{ $factura->uuid }}</span></span>
                <span>{{ __('Fecha') }}: {{ $factura->fecha->format('d/m/Y') }}</span>
                <span>{{ __('Emisor') }}: {{ $factura->emisor_rfc }}</span>
                <span>{{ __('Receptor') }}: {{ $factura->receptor_rfc }}</span>
                @php($estilosSat = ['VIGENTE' => 'bg-green-100 text-green-800', 'CANCELADO' => 'bg-red-100 text-red-800', 'NO_ENCONTRADO' => 'bg-blue-100 text-blue-800', 'ERROR' => 'bg-amber-100 text-amber-800'])
                <span class="inline-flex items-center gap-2">
                    {{ __('SAT') }}:
                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $estilosSat[$factura->verificacion_sat] ?? 'bg-gray-100 text-gray-700' }}">{{ $factura->verificacion_sat }}</span>
                    @if ($factura->sat_consultado_at)<span class="text-xs text-gray-500">{{ $factura->sat_consultado_at->format('d/m/Y H:i') }}</span>@endif
                    @if ($factura->sat_es_cancelable)<span class="text-xs text-gray-500">· {{ $factura->sat_es_cancelable }}@if ($factura->sat_estatus_cancelacion && $factura->sat_estatus_cancelacion !== 'Sin cancelación en proceso') · {{ $factura->sat_estatus_cancelacion }}@endif</span>@endif
                </span>
                <form method="POST" action="{{ route('insumos.facturas.verificar-sat', $factura) }}" class="inline">
                    @csrf
                    <button type="submit" class="text-brand-teal hover:underline">{{ __('Verificar ahora') }}</button>
                </form>
                <a href="{{ route('insumos.facturas.archivo', [$factura, 'xml']) }}" class="text-brand-teal hover:underline">XML</a>
                @if ($factura->pdf_ruta)<a href="{{ route('insumos.facturas.archivo', [$factura, 'pdf']) }}" target="_blank" rel="noopener" class="text-brand-teal hover:underline">PDF</a>@endif
                <form method="POST" action="{{ route('insumos.facturas.destroy', $factura) }}" class="inline"
                      onsubmit="return confirm('{{ __('¿Eliminar esta factura?') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-red-600 hover:underline">{{ __('Eliminar') }}</button>
                </form>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                <x-kpi-card :label="__('Subtotal')" :value="$dinero((float) $factura->subtotal)" />
                <x-kpi-card :label="__('IVA')" :value="$dinero((float) $factura->iva)" />
                <x-kpi-card :label="__('ISR retenido')" :value="$dinero((float) $factura->isr_retenido)" />
                <x-kpi-card :label="__('Total')" :value="$dinero((float) $factura->total)" />
                <x-kpi-card :label="__('Cotización')" :value="$factura->cotizacion ? $factura->cotizacion->folio : '—'" :hint="$factura->vinculo_cotizacion ? __('Vinculada por :como', ['como' => $factura->vinculo_cotizacion]) : ($factura->referencia_cot ? __('Notas: COT-:n', ['n' => $factura->referencia_cot]) : '')" />
            </div>

            @include('insumos.facturas._comprobacion', ['factura' => $factura, 'vista' => $vistaComprobacion])

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <form method="POST" action="{{ route('insumos.facturas.update', $factura) }}" class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                    @csrf
                    @method('PUT')
                    <h3 class="font-semibold text-brand-green">{{ __('Cotización y estación') }}</h3>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="cotizacion_id" :value="__('Cotización')" />
                            <select id="cotizacion_id" name="cotizacion_id" class="{{ $campo }}">
                                <option value="">{{ __('— Buscar automáticamente —') }}</option>
                                @foreach ($cotizaciones as $cotizacion)
                                    <option value="{{ $cotizacion->id }}" @selected($factura->cotizacion_id === $cotizacion->id)>{{ $cotizacion->proveedor }} · {{ $cotizacion->folio }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="estacion_id" :value="__('Estación')" />
                            <select id="estacion_id" name="estacion_id" class="{{ $campo }}">
                                <option value="">{{ __('— Deducir de las partidas —') }}</option>
                                @foreach ($estaciones as $estacion)
                                    <option value="{{ $estacion->id }}" @selected($factura->estacion_id === $estacion->id)>{{ $estacion->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end"><x-secondary-button type="submit">{{ __('Guardar y analizar de nuevo') }}</x-secondary-button></div>
                </form>

                <form method="POST" action="{{ route('insumos.facturas.pdf', $factura) }}" enctype="multipart/form-data" class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                    @csrf
                    <h3 class="font-semibold text-brand-green">{{ __('PDF de respaldo') }}</h3>
                    <input name="archivo" type="file" required accept=".pdf" class="mt-3 block w-full text-sm text-gray-700">
                    <div class="mt-4 flex justify-end"><x-secondary-button type="submit">{{ __('Guardar PDF') }}</x-secondary-button></div>
                </form>
            </div>

            @if ($generales->isNotEmpty())
                <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                    <h3 class="font-semibold text-brand-green">{{ __('Hallazgos de la factura') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($generales as $h)
                            <li><span class="mr-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $estilos[$h->tipo] ?? '' }}">{{ $h->tipo }}</span> <span class="font-mono text-xs">{{ $h->codigo }}</span> — {{ $h->detalle }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <table class="min-w-full text-sm">
                    <thead class="bg-brand-mist text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                        <tr>
                            <th class="px-3 py-3">{{ __('N°') }}</th>
                            <th class="px-3 py-3">{{ __('Concepto facturado') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('Cant.') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('P.U.') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('Importe') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('IVA') }}</th>
                            <th class="px-3 py-3">{{ __('Vinculado con') }}</th>
                            <th class="px-3 py-3">{{ __('Hallazgos') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($factura->conceptos as $concepto)
                            <tr class="align-top">
                                <td class="px-3 py-2">{{ $concepto->numero }}</td>
                                <td class="px-3 py-2">{{ $concepto->descripcion }}@if ($concepto->no_identificacion)<span class="block text-xs text-gray-500">{{ $concepto->no_identificacion }}</span>@endif</td>
                                <td class="px-3 py-2 text-right">{{ (float) $concepto->cantidad }}</td>
                                <td class="px-3 py-2 text-right">{{ $dinero((float) $concepto->valor_unitario) }}</td>
                                <td class="px-3 py-2 text-right">{{ $dinero((float) $concepto->importe) }}</td>
                                <td class="px-3 py-2 text-right">{{ $concepto->iva_tasa === null ? '—' : (float) $concepto->iva_tasa.' %' }}</td>
                                <td class="px-3 py-2">
                                    <form method="POST" action="{{ route('insumos.facturas.concepto', $concepto) }}" class="flex items-center gap-1">
                                        @csrf
                                        @method('PUT')
                                        <select name="partida_id" class="{{ $campo }} min-w-[14rem]" onchange="this.form.submit()">
                                            <option value="">{{ __('— Automático —') }}</option>
                                            @foreach ($partidas as $partida)
                                                <option value="{{ $partida['id'] }}" @selected($concepto->solicitud_insumo_partida_id === $partida['id'])>{{ $partida['etiqueta'] }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                    @if ($concepto->vinculo)<span class="text-xs text-gray-500">{{ __('por :v', ['v' => $concepto->vinculo]) }}</span>@endif
                                </td>
                                <td class="px-3 py-2">
                                    @foreach ($hallazgosPorConcepto->get($concepto->id, collect()) as $h)
                                        <p class="mb-1"><span class="mr-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $estilos[$h->tipo] ?? '' }}">{{ $h->tipo }}</span><span class="font-mono text-xs">{{ $h->codigo }}</span> <span class="text-xs">{{ $h->detalle }}</span></p>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div>
                <a href="{{ route('insumos.facturas.index', ['anio' => $factura->anio, 'mes' => $factura->mes]) }}" class="text-sm text-gray-600 hover:text-brand-green">← {{ __('Volver') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
