@php
    /** @var int $anio */
    /** @var \Illuminate\Support\Collection<int, array{estacion: \App\Models\Estacion, abordan: array<int, int>, boletos_vendidos: array<int, int>}> $resumen */
    /** @var array<int, int> $totalMensualAbordan */
    /** @var array<int, int> $totalMensualBoletos */
    /** @var \Illuminate\Support\Collection<int, bool> $puedeEditar */
    $meses = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];
    $anualChartConfig = [
        'type' => 'line',
        'data' => [
            'labels' => array_values($meses),
            'datasets' => [
                [
                    'label' => __('Pasajeros (abordan)'),
                    'data' => array_values($totalMensualAbordan),
                    'borderColor' => 'rgb(20, 108, 67)',
                    'backgroundColor' => 'rgba(20, 108, 67, 0.15)',
                    'tension' => 0.25,
                    'fill' => true,
                ],
                [
                    'label' => __('Boletos vendidos'),
                    'data' => array_values($totalMensualBoletos),
                    'borderColor' => 'rgb(45, 156, 219)',
                    'backgroundColor' => 'rgba(45, 156, 219, 0.15)',
                    'tension' => 0.25,
                    'fill' => true,
                ],
            ],
        ],
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => ['y' => ['beginAtZero' => true]],
        ],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Estadísticas') }} — {{ __('Flujo de pasajeros y boletos vendidos') }}
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
                <form method="GET" action="{{ route('estadisticas.index') }}" class="flex flex-wrap items-end gap-2">
                    <div class="max-w-xs">
                        <x-input-label for="anio-estadisticas" :value="__('Año')" />
                        <x-text-input id="anio-estadisticas" name="anio" type="number" class="block mt-1 w-full"
                                      value="{{ $anio }}" min="2000" max="2100" />
                    </div>
                    <x-secondary-button type="submit">{{ __('Ver este año') }}</x-secondary-button>
                </form>
            </div>

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="mb-3 text-sm font-medium text-gray-600">
                    {{ __('Total de pasajeros y boletos vendidos por mes — :anio', ['anio' => $anio]) }}
                </p>
                @if (array_sum($totalMensualAbordan) > 0 || array_sum($totalMensualBoletos) > 0)
                    <div class="relative h-64 sm:h-72">
                        <canvas id="estadisticas-anual-chart"></canvas>
                    </div>
                @else
                    <p class="text-sm text-gray-500">{{ __('No hay datos capturados para este año todavía.') }}</p>
                @endif
            </div>

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <table class="min-w-full divide-y divide-brand-green/10 text-sm">
                    <thead class="bg-brand-mist/60">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Estación') }}</th>
                            <th scope="col" class="px-4 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Indicador') }}</th>
                            @foreach ($meses as $mes)
                                <th scope="col" class="px-3 py-3 text-right font-heading font-semibold text-brand-green">{{ $mes }}</th>
                            @endforeach
                            <th scope="col" class="px-3 py-3 text-right font-heading font-semibold text-brand-green">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-green/10">
                        @forelse ($resumen as $fila)
                            @php $estacion = $fila['estacion']; @endphp
                            <tr class="hover:bg-brand-mist/30">
                                <td rowspan="2" class="px-4 py-3 align-top font-medium text-brand-green">
                                    <a href="{{ route('estadisticas.show', ['estacion' => $estacion->id, 'anio' => $anio]) }}" class="hover:underline">
                                        {{ $estacion->nombre }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ __('Abordan') }}</td>
                                @foreach ($meses as $numeroMes => $etiqueta)
                                    <td class="px-3 py-3 text-right text-gray-700">{{ number_format($fila['abordan'][$numeroMes]) }}</td>
                                @endforeach
                                <td rowspan="2" class="px-3 py-3 align-top text-right">
                                    @if ($puedeEditar[$estacion->id] ?? false)
                                        <a href="{{ route('estadisticas.show', ['estacion' => $estacion->id, 'anio' => $anio]) }}"
                                           class="font-medium text-brand-teal hover:text-brand-green-dark">
                                            {{ __('Editar') }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                            <tr class="hover:bg-brand-mist/30">
                                <td class="px-4 py-3 text-gray-500">{{ __('Boletos vendidos') }}</td>
                                @foreach ($meses as $numeroMes => $etiqueta)
                                    <td class="px-3 py-3 text-right text-gray-700">{{ number_format($fila['boletos_vendidos'][$numeroMes]) }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 3 + count($meses) }}" class="px-4 py-6 text-center text-gray-500">
                                    {{ __('No hay estaciones visibles para tu cuenta.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    @if (array_sum($totalMensualAbordan) > 0 || array_sum($totalMensualBoletos) > 0)
        <script>
            window.__pendingCharts = window.__pendingCharts || [];
            window.__pendingCharts.push({ canvasId: 'estadisticas-anual-chart', config: @json($anualChartConfig) });
        </script>
    @endif
</x-app-layout>
