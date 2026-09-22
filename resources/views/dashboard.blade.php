<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="text-sm text-gray-500">{{ __('Sesión activa') }}</p>
                <p class="mt-1 font-heading text-lg font-semibold text-brand-green">
                    {{ __('Bienvenido(a),') }} {{ Auth::user()->name }}
                </p>
            </div>

            <div>
                <h3 class="mb-3 font-heading text-sm font-semibold uppercase tracking-wide text-gray-500">
                    {{ __('Indicadores clave') }}
                </h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($kpis as $kpi)
                        <x-kpi-card :label="$kpi['label']" :value="$kpi['value']" :hint="$kpi['hint']" />
                    @endforeach
                </div>
            </div>

            <div>
                <h3 class="mb-3 font-heading text-sm font-semibold uppercase tracking-wide text-gray-500">
                    {{ __('Gráficas e indicadores') }}
                </h3>
                @if ($estadisticasChart && count($estadisticasChart['labels']) > 0)
                    @php
                        $dashboardChartConfig = [
                            'type' => 'bar',
                            'data' => [
                                'labels' => $estadisticasChart['labels'],
                                'datasets' => [
                                    [
                                        'label' => __('Pasajeros (abordan)'),
                                        'data' => $estadisticasChart['values'],
                                        'backgroundColor' => 'rgba(20, 108, 67, 0.6)',
                                    ],
                                    [
                                        'label' => __('Boletos vendidos'),
                                        'data' => $estadisticasChart['valuesBoletos'],
                                        'backgroundColor' => 'rgba(45, 156, 219, 0.6)',
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
                    {{-- Filtra la gráfica por nombre de estación en el navegador:
                         los datos ya llegaron autorizados al render inicial, así
                         que no hace falta un endpoint nuevo para un simple filtro
                         de texto sobre lo que ya está en pantalla. La lógica del
                         componente vive en resources/js/app.js
                         (Alpine.data('estadisticasBuscador', ...)) — aquí solo se
                         invoca con los datos de esta vista.

                         x-data va entre comillas SIMPLES a propósito: @json()
                         genera JSON, que usa comillas DOBLES para cada string
                         (eso es sintaxis JSON, no algo que @json() pueda evitar
                         — sus flags JSON_HEX_* solo escapan comillas que
                         aparecen DENTRO de un valor, nunca las comillas
                         estructurales que delimitan cada string). Con x-data
                         entre comillas dobles, el navegador corta el atributo
                         en la primera comilla del JSON y el resto queda como
                         texto/atributos sueltos — bug real, reproducido con la
                         consola del navegador. Por eso también el literal
                         'estadisticas-dashboard-chart' pasa a comillas dobles
                         aquí abajo (ya no puede usar comillas simples, que
                         ahora delimitan todo el atributo). --}}
                    <div
                        x-data='estadisticasBuscador(@json($estadisticasChart['labels']), @json($estadisticasChart['values']), @json($estadisticasChart['valuesBoletos']), "estadisticas-dashboard-chart")'
                        class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6"
                    >
                        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm font-medium text-gray-600">
                                {{ __('Pasajeros y boletos vendidos por estación — mes actual') }}
                            </p>
                            <div class="w-full sm:max-w-xs">
                                <label for="buscar-estadisticas-estacion" class="sr-only">
                                    {{ __('Buscar estación por nombre') }}
                                </label>
                                <x-text-input id="buscar-estadisticas-estacion" type="search"
                                              class="block w-full text-sm"
                                              placeholder="{{ __('Buscar estación por nombre…') }}"
                                              x-model="query" @input="actualizarGrafica()" />
                            </div>
                        </div>
                        <p x-show="query.trim() !== '' && indicesFiltrados.length === 0" x-cloak
                           class="mb-3 text-sm text-gray-500">
                            {{ __('Ninguna estación coincide con la búsqueda.') }}
                        </p>
                        <div class="relative h-64 sm:h-72">
                            <canvas id="estadisticas-dashboard-chart"></canvas>
                        </div>
                    </div>
                    <script>
                        window.__pendingCharts = window.__pendingCharts || [];
                        window.__pendingCharts.push({ canvasId: 'estadisticas-dashboard-chart', config: @json($dashboardChartConfig) });
                    </script>
                @else
                    <div class="rounded-xl border border-dashed border-brand-green/20 bg-white/60 p-8 text-center shadow-sm shadow-brand-green/5 sm:p-12">
                        <p class="font-heading text-base font-semibold text-brand-green">
                            {{ __('Sin datos todavía') }}
                        </p>
                        <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                            {{ __('Aún no hay estadísticas capturadas para el mes actual. La gráfica aparecerá aquí en cuanto se registren datos en el módulo Estadísticas.') }}
                        </p>
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                <h3 class="font-heading text-sm font-semibold uppercase tracking-wide text-gray-500">
                    {{ __('Accesos directos') }}
                </h3>
                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('agenda.personal.index') }}"
                       class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-brand-green/20 bg-white px-4 py-2 text-sm font-medium text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                        {{ __('Agenda Zona Oriente') }}
                    </a>
                    @can('view-asistencia-zona')
                        <a href="{{ route('asistencia.zona.index') }}"
                           class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-brand-green/20 bg-white px-4 py-2 text-sm font-medium text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            {{ __('Asistencia Zona Oriente') }}
                        </a>
                    @endcan
                    @can('viewAny', App\Models\EstadisticaDiaria::class)
                        <a href="{{ route('estadisticas.index') }}"
                           class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-brand-green/20 bg-white px-4 py-2 text-sm font-medium text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            {{ __('Estadísticas') }}
                        </a>
                    @endcan
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
