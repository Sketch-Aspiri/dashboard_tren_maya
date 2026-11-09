@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var array<int, string> $meses */
    /** @var array<string, mixed> $resumen */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Factura> $facturas */
    $dinero = fn (float $monto) => '$'.number_format($monto, 2);
    $campo = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal text-sm';
    $resultados = session('resultados_facturas', []);
    $estilos = [
        'OK' => 'bg-green-100 text-green-800',
        'ALERTA' => 'bg-amber-100 text-amber-800',
        'REVISAR' => 'bg-blue-100 text-blue-800',
        'ERROR' => 'bg-red-100 text-red-800',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Recursos materiales') }} — {{ __('Facturas') }} {{ $meses[$mes] }} {{ $anio }}
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

            @if ($resultados !== [])
                <div class="rounded-xl border border-brand-green/10 bg-white p-4 text-sm shadow-sm shadow-brand-green/5">
                    <p class="font-semibold text-brand-green">{{ __('Resultado de la última carga') }}</p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($resultados as $r)
                            <li class="{{ $r['ok'] ? 'text-green-800' : 'text-red-700' }}">
                                {{ $r['ok'] ? '✓' : '✗' }} {{ $r['archivo'] }} — {{ $r['mensaje'] }}
                                @if ($r['ok'])<a href="{{ route('insumos.facturas.show', $r['factura_id']) }}" class="ml-1 text-brand-teal hover:underline">{{ __('Ver') }}</a>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="GET" action="{{ route('insumos.facturas.index') }}" class="flex flex-wrap items-end gap-2">
                    <div>
                        <x-input-label for="mes-fac" :value="__('Mes')" />
                        <select id="mes-fac" name="mes" class="{{ $campo }}">
                            @foreach ($meses as $numero => $nombre)<option value="{{ $numero }}" @selected($numero === $mes)>{{ $nombre }}</option>@endforeach
                        </select>
                    </div>
                    <div class="max-w-xs">
                        <x-input-label for="anio-fac" :value="__('Año')" />
                        <x-text-input id="anio-fac" name="anio" type="number" class="block mt-1 w-full" value="{{ $anio }}" min="2000" max="2100" />
                    </div>
                    <x-secondary-button type="submit">{{ __('Ver mes') }}</x-secondary-button>
                    <a href="{{ route('insumos.facturas.descargar', ['anio' => $anio, 'mes' => $mes, 'tipo' => 'compra']) }}"
                       class="inline-flex min-h-[44px] items-center rounded-md border border-brand-teal px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Compra de insumos (Excel)') }}</a>
                    <a href="{{ route('insumos.facturas.descargar', ['anio' => $anio, 'mes' => $mes, 'tipo' => 'reporte']) }}"
                       class="inline-flex min-h-[44px] items-center rounded-md border border-brand-teal px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Reporte de comprobación (Excel)') }}</a>
                </form>
            </div>

            <form method="POST" action="{{ route('insumos.facturas.store') }}" enctype="multipart/form-data"
                  class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                @csrf
                <input type="hidden" name="anio" value="{{ $anio }}">
                <input type="hidden" name="mes" value="{{ $mes }}">
                <h3 class="font-semibold text-brand-green">{{ __('Cargar facturas (XML CFDI)') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Elige uno o varios XML. Cada uno se compara con su cotización y con las partidas de :mes; el PDF se puede agregar después desde la factura.', ['mes' => $meses[$mes]]) }}</p>
                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <input name="xml[]" type="file" multiple required accept=".xml" class="block text-sm text-gray-700">
                    <x-primary-button type="submit">{{ __('Cargar') }}</x-primary-button>
                </div>
            </form>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-kpi-card :label="__('Facturas cargadas')" :value="$resumen['facturas']" :hint="__('OK :o · Alerta :a · Revisar :r · Error :e', ['o' => $resumen['facturas_por_estado']['OK'], 'a' => $resumen['facturas_por_estado']['ALERTA'], 'r' => $resumen['facturas_por_estado']['REVISAR'], 'e' => $resumen['facturas_por_estado']['ERROR']])" />
                <x-kpi-card :label="$resumen['excede'] ? __('Excede el fondo') : __('Total facturado')" :value="$dinero($resumen['total'])" :hint="__('Fondo rotatorio: :f', ['f' => $dinero($resumen['fondo'])])" />
                <x-kpi-card :label="__('Pendiente por ejercer')" :value="$dinero($resumen['pendiente'])" />
                <x-kpi-card :label="__('No facturado (sin IVA)')" :value="$dinero($resumen['no_facturado'])" :hint="__('Pedido o cotizado y no comprado.')" />
            </div>

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <table class="min-w-full text-sm">
                    <thead class="bg-brand-mist text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                        <tr>
                            <th class="px-3 py-3">{{ __('Estación') }}</th>
                            <th class="px-3 py-3">{{ __('Emisor') }}</th>
                            <th class="px-3 py-3">{{ __('Factura') }}</th>
                            <th class="px-3 py-3">{{ __('Fecha') }}</th>
                            <th class="px-3 py-3">{{ __('Cotización') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('Total') }}</th>
                            <th class="px-3 py-3">{{ __('Estado') }}</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($facturas as $factura)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $factura->estacion?->nombre ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $factura->emisor_nombre }}</td>
                                <td class="px-3 py-2">{{ $factura->referencia() }}</td>
                                <td class="px-3 py-2">{{ $factura->fecha->format('d/m/Y') }}</td>
                                <td class="px-3 py-2">{{ $factura->cotizacion ? $factura->cotizacion->proveedor.' '.$factura->cotizacion->folio : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $dinero((float) $factura->total) }}</td>
                                <td class="px-3 py-2"><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $estilos[$factura->estado] ?? '' }}">{{ $factura->estado }}</span></td>
                                <td class="px-3 py-2 text-right"><a href="{{ route('insumos.facturas.show', $factura) }}" class="text-brand-teal hover:underline">{{ __('Ver') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-8 text-center text-gray-500">{{ __('Aún no hay facturas de este mes.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>
                <a href="{{ route('insumos.index', ['anio' => $anio, 'mes' => $mes]) }}" class="text-sm text-gray-600 hover:text-brand-green">← {{ __('Volver') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
