@php
    /** @var int $anio */
    /** @var list<array{tipo: \App\Enums\TipoServicio, filas: list<array{servicio: \App\Models\ServicioEstacion, meses: array<int, float|null>, total: float}>, totalesMensuales: array<int, float>, total: float}> $resumen */
    /** @var \Illuminate\Support\Collection<int, bool> $puedeEditar */
    $meses = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];
    $colores = ['energia_electrica' => '20, 108, 67', 'agua' => '45, 156, 219'];
    $dinero = fn (?float $monto) => $monto === null || $monto == 0.0 ? '—' : '$'.number_format($monto, 0);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Recursos financieros') }} — {{ __('Gasto energético') }} {{ $anio }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-xl border border-brand-green/10 bg-brand-mist p-4 text-sm text-brand-green">
                    {{ session('status') }}
                </div>
            @endif

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="GET" action="{{ route('estadisticas.gasto-energetico.index') }}" class="flex flex-wrap items-end gap-2">
                    <div class="max-w-xs">
                        <x-input-label for="anio-gasto" :value="__('Año')" />
                        <x-text-input id="anio-gasto" name="anio" type="number" class="block mt-1 w-full"
                                      value="{{ $anio }}" min="2000" max="2100" />
                    </div>
                    <x-secondary-button type="submit">{{ __('Ver este año') }}</x-secondary-button>
                </form>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($resumen as $bloque)
                    <x-kpi-card
                        :label="__('Total pagado en :servicio — :anio', ['servicio' => mb_strtolower($bloque['tipo']->label()), 'anio' => $anio])"
                        :value="'$'.number_format($bloque['total'], 0)"
                        :hint="__('Suma de los meses con pago registrado en el año.')" />
                @endforeach
            </div>

            @foreach ($resumen as $bloque)
                @php
                    $tipo = $bloque['tipo'];
                    $canvasId = 'gasto-'.$tipo->value.'-chart';
                    $conObservaciones = collect($bloque['filas'])->filter(fn ($fila) => filled($fila['servicio']->observaciones));
                    $chartConfig = [
                        'type' => 'bar',
                        'data' => [
                            'labels' => array_values($meses),
                            'datasets' => [[
                                'label' => __('Pagado en :servicio', ['servicio' => mb_strtolower($tipo->label())]),
                                'data' => array_values($bloque['totalesMensuales']),
                                'backgroundColor' => 'rgba('.$colores[$tipo->value].', 0.7)',
                                'borderColor' => 'rgb('.$colores[$tipo->value].')',
                                'borderWidth' => 1,
                            ]],
                        ],
                        'options' => [
                            'responsive' => true,
                            'maintainAspectRatio' => false,
                            'plugins' => ['legend' => ['display' => false]],
                            'scales' => ['y' => ['beginAtZero' => true]],
                        ],
                    ];
                @endphp

                <section class="space-y-4" aria-labelledby="titulo-{{ $tipo->value }}">
                    <h3 id="titulo-{{ $tipo->value }}" class="font-heading text-lg font-semibold text-brand-green">
                        {{ __('Pago de servicio de :servicio', ['servicio' => mb_strtolower($tipo->label())]) }}
                    </h3>

                    <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                        <p class="mb-3 text-sm font-medium text-gray-600">
                            {{ __('Total de la zona por mes — :anio', ['anio' => $anio]) }}
                        </p>
                        @if ($bloque['total'] > 0)
                            <div class="relative h-56 sm:h-64">
                                <canvas id="{{ $canvasId }}"></canvas>
                            </div>
                            <script>
                                window.__pendingCharts = window.__pendingCharts || [];
                                window.__pendingCharts.push({ canvasId: '{{ $canvasId }}', config: @json($chartConfig) });
                            </script>
                        @else
                            <p class="text-sm text-gray-500">{{ __('No hay pagos registrados para este año.') }}</p>
                        @endif
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                        <table class="min-w-full divide-y divide-brand-green/10 text-sm">
                            <thead class="bg-brand-mist/60">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Estación') }}</th>
                                    @foreach ($meses as $etiqueta)
                                        <th scope="col" class="px-3 py-3 text-right font-heading font-semibold text-brand-green">{{ $etiqueta }}</th>
                                    @endforeach
                                    <th scope="col" class="px-3 py-3 text-right font-heading font-semibold text-brand-green">{{ __('Total') }}</th>
                                    <th scope="col" class="px-3 py-3 text-right font-heading font-semibold text-brand-green">{{ __('Acciones') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-green/10">
                                @forelse ($bloque['filas'] as $fila)
                                    @php $servicio = $fila['servicio']; @endphp
                                    <tr class="hover:bg-brand-mist/30">
                                        <td class="px-4 py-3">
                                            <p class="font-medium text-brand-green">
                                                <a href="{{ route('estadisticas.gasto-energetico.show', ['estacion' => $servicio->estacion_id, 'anio' => $anio]) }}" class="hover:underline">
                                                    {{ $servicio->estacion->nombre }}
                                                </a>
                                            </p>
                                            <p class="text-xs text-gray-500">{{ collect([$servicio->proveedor, $servicio->contrato])->filter()->implode(' · ') ?: '—' }}</p>
                                        </td>
                                        @foreach ($meses as $numero => $etiqueta)
                                            <td class="whitespace-nowrap px-3 py-3 text-right text-gray-700">{{ $dinero($fila['meses'][$numero]) }}</td>
                                        @endforeach
                                        <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-brand-green">{{ $dinero($fila['total']) }}</td>
                                        <td class="px-3 py-3 text-right">
                                            @if ($puedeEditar[$servicio->estacion_id] ?? false)
                                                <a href="{{ route('estadisticas.gasto-energetico.show', ['estacion' => $servicio->estacion_id, 'anio' => $anio]) }}"
                                                   class="font-medium text-brand-teal hover:text-brand-green-dark">
                                                    {{ __('Editar') }}
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ 3 + count($meses) }}" class="px-4 py-6 text-center text-gray-500">{{ __('Sin información cargada.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if ($bloque['filas'] !== [])
                                <tfoot class="bg-brand-mist/60">
                                    <tr>
                                        <th scope="row" class="px-4 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Total zona') }}</th>
                                        @foreach ($meses as $numero => $etiqueta)
                                            <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-brand-green">{{ $dinero($bloque['totalesMensuales'][$numero]) }}</td>
                                        @endforeach
                                        <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-brand-green">{{ $dinero($bloque['total']) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>

                    @if ($conObservaciones->isNotEmpty())
                        <details class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5">
                            <summary class="cursor-pointer text-sm font-medium text-brand-green">
                                {{ __('Observaciones') }} ({{ $conObservaciones->count() }})
                            </summary>
                            <dl class="mt-3 space-y-3 text-sm">
                                @foreach ($conObservaciones as $fila)
                                    <div>
                                        <dt class="font-medium text-gray-900">{{ $fila['servicio']->estacion->nombre }}</dt>
                                        <dd class="mt-0.5 whitespace-pre-line text-gray-600">{{ $fila['servicio']->observaciones }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </details>
                    @endif
                </section>
            @endforeach

            <p class="text-xs text-gray-500">
                {{ __('Montos en pesos, tal como se reportan en el informe de reducción de energía eléctrica (ANEXO B). Un guion indica que no hubo pago registrado en el mes.') }}
            </p>
        </div>
    </div>

</x-app-layout>
