@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var array<int, string> $meses */
    /** @var array<string, mixed> $resumen */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Cotizacion> $cotizaciones */
    $dinero = fn (float $monto) => '$'.number_format($monto, 2);
    $campo = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal text-sm';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Recursos materiales') }} — {{ __('Cotizaciones') }} {{ $meses[$mes] }} {{ $anio }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-xl border border-brand-green/10 bg-brand-mist p-4 text-sm text-brand-green">{{ session('status') }}</div>
            @endif

            <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="GET" action="{{ route('insumos.cotizaciones.index') }}" class="flex flex-wrap items-end gap-2">
                    <div>
                        <x-input-label for="mes-cot" :value="__('Mes')" />
                        <select id="mes-cot" name="mes" class="{{ $campo }}">
                            @foreach ($meses as $numero => $nombre)
                                <option value="{{ $numero }}" @selected($numero === $mes)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="max-w-xs">
                        <x-input-label for="anio-cot" :value="__('Año')" />
                        <x-text-input id="anio-cot" name="anio" type="number" class="block mt-1 w-full" value="{{ $anio }}" min="2000" max="2100" />
                    </div>
                    <x-secondary-button type="submit">{{ __('Ver mes') }}</x-secondary-button>
                    <a href="{{ route('insumos.cotizaciones.create', ['anio' => $anio, 'mes' => $mes]) }}"
                       class="inline-flex min-h-[44px] items-center rounded-md bg-brand-green px-4 text-xs font-semibold uppercase tracking-widest text-white hover:bg-brand-green-dark">{{ __('Nueva cotización') }}</a>
                    <a href="{{ route('insumos.cotizaciones.descargar', ['anio' => $anio, 'mes' => $mes]) }}"
                       class="inline-flex min-h-[44px] items-center rounded-md border border-brand-teal px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Descargar precios reales (Excel)') }}</a>
                </form>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-kpi-card :label="__('Partidas actualizadas')" :value="$resumen['actualizadas'].' / '.$resumen['partidas']" :hint="__('Ya tienen precio cotizado.')" />
                <x-kpi-card :label="__('En revisión')" :value="$resumen['en_revision']" :hint="__('Cotizadas pero no aceptadas todavía.')" />
                <x-kpi-card :label="__('Sin cotización')" :value="$resumen['sin_cotizacion']" :hint="__('Conservan su precio estimado.')" />
                <x-kpi-card :label="__('Fuera del documento')" :value="$resumen['fuera_del_documento']" :hint="__('Cotizado pero no solicitado.')" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-kpi-card :label="__('Subtotal estimado')" :value="$dinero($resumen['subtotal_estimado'])" />
                <x-kpi-card :label="__('Subtotal con precios reales')" :value="$dinero($resumen['subtotal_real'])" />
                <x-kpi-card :label="$resumen['excede'] ? __('Excede el fondo') : __('Total con IVA')" :value="$dinero($resumen['total_con_iva'])"
                            :hint="__('Fondo rotatorio: :f', ['f' => $dinero($resumen['fondo'])])" />
            </div>

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <table class="min-w-full text-sm">
                    <thead class="bg-brand-mist text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                        <tr>
                            <th class="px-4 py-3">{{ __('Proveedor') }}</th>
                            <th class="px-4 py-3">{{ __('Folio') }}</th>
                            <th class="px-4 py-3">{{ __('Fecha') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Renglones') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                            <th class="px-4 py-3">{{ __('Validación') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($cotizaciones as $cotizacion)
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-900">{{ $cotizacion->proveedor }}
                                    @if ($cotizacion->leida_con_ia)<span class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-xs text-blue-800">IA</span>@endif
                                </td>
                                <td class="px-4 py-2">{{ $cotizacion->folio }}</td>
                                <td class="px-4 py-2">{{ $cotizacion->fecha?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-4 py-2 text-right">{{ $cotizacion->renglones_count }}</td>
                                <td class="px-4 py-2 text-right">{{ $cotizacion->total === null ? '—' : $dinero((float) $cotizacion->total) }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $cotizacion->validacion === 'OK' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ $cotizacion->validacion }}</span>
                                </td>
                                <td class="px-4 py-2 text-right"><a href="{{ route('insumos.cotizaciones.show', $cotizacion) }}" class="text-brand-teal hover:underline">{{ __('Ver') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">{{ __('Aún no hay cotizaciones de este mes.') }}</td></tr>
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
