@php
    /** @var \App\Models\Estacion $estacion */
    /** @var int $anio */
    /** @var int $mes */
    /** @var \Illuminate\Support\Collection<int, \App\Models\EstadisticaDiaria> $registros */
    /** @var \Illuminate\Support\Collection<int, bool> $puedeEliminar */
    $diasEnElMes = \Carbon\CarbonImmutable::create($anio, $mes, 1)->daysInMonth;
    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $abordanPorDia = [];
    $boletosPorDia = [];
    for ($dia = 1; $dia <= $diasEnElMes; $dia++) {
        $registro = $registros->get($dia);
        $abordanPorDia[] = $registro?->abordan;
        $boletosPorDia[] = $registro?->boletos_vendidos;
    }
    $mensualChartConfig = [
        'type' => 'bar',
        'data' => [
            'labels' => range(1, $diasEnElMes),
            'datasets' => [
                [
                    'label' => __('Abordan'),
                    'data' => $abordanPorDia,
                    'backgroundColor' => 'rgba(20, 108, 67, 0.6)',
                ],
                [
                    'label' => __('Boletos vendidos'),
                    'data' => $boletosPorDia,
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

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Estadísticas') }} — {{ $estacion->nombre }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-xl border border-brand-green/10 bg-brand-mist p-4 text-sm text-brand-green">
                    {{ session('status') }}
                </div>
            @endif

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="GET" action="{{ route('estadisticas.show', ['estacion' => $estacion->id]) }}" class="flex flex-wrap items-end gap-2">
                    <div class="max-w-[10rem]">
                        <x-input-label for="mes-estadisticas" :value="__('Mes')" />
                        <select id="mes-estadisticas" name="mes" class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                            @foreach ($meses as $numeroMes => $nombreMes)
                                <option value="{{ $numeroMes }}" @selected($numeroMes === $mes)>{{ $nombreMes }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="max-w-[8rem]">
                        <x-input-label for="anio-estadisticas" :value="__('Año')" />
                        <x-text-input id="anio-estadisticas" name="anio" type="number" class="block mt-1 w-full" value="{{ $anio }}" min="2000" max="2100" />
                    </div>
                    <x-secondary-button type="submit">{{ __('Ver este mes') }}</x-secondary-button>
                    <a href="{{ route('estadisticas.index', ['anio' => $anio]) }}" class="text-sm text-brand-green hover:underline">
                        {{ __('Volver al resumen anual') }}
                    </a>
                </form>
            </div>

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="mb-3 text-sm font-medium text-gray-600">
                    {{ __(':mes :anio', ['mes' => $meses[$mes], 'anio' => $anio]) }}
                </p>
                @if ($registros->isNotEmpty())
                    <div class="relative h-64 sm:h-72">
                        <canvas id="estadisticas-mensual-chart"></canvas>
                    </div>
                @else
                    <p class="text-sm text-gray-500">{{ __('No hay datos capturados para este mes todavía.') }}</p>
                @endif
            </div>

            <form method="POST" action="{{ route('estadisticas.update', ['estacion' => $estacion->id]) }}" class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                @csrf
                @method('PUT')
                <input type="hidden" name="anio" value="{{ $anio }}">
                <input type="hidden" name="mes" value="{{ $mes }}">

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-brand-green/10 text-sm">
                        <thead class="bg-brand-mist/60">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-left font-heading font-semibold text-brand-green">{{ __('Día') }}</th>
                                <th scope="col" class="px-3 py-2 text-left font-heading font-semibold text-brand-green">{{ __('Abordan') }}</th>
                                <th scope="col" class="px-3 py-2 text-left font-heading font-semibold text-brand-green">{{ __('Boletos vendidos') }}</th>
                                <th scope="col" class="px-3 py-2 text-left font-heading font-semibold text-brand-green">{{ __('Acciones') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-green/10">
                            @for ($dia = 1; $dia <= $diasEnElMes; $dia++)
                                @php
                                    $registro = $registros->get($dia);
                                    $oldAbordan = old('dias.'.$dia.'.abordan', $registro?->abordan);
                                    $oldBoletosVendidos = old('dias.'.$dia.'.boletos_vendidos', $registro?->boletos_vendidos);
                                @endphp
                                <tr class="{{ $registro ? 'bg-brand-mist/30' : '' }}">
                                    <td class="px-3 py-2 font-medium text-gray-700">
                                        <div class="flex items-center gap-2">
                                            <span>{{ $dia }}</span>
                                            @if ($registro)
                                                <span class="inline-flex items-center rounded-full bg-brand-mist px-2 py-0.5 text-[11px] font-semibold text-brand-green" title="{{ __('Este día ya tiene datos capturados — edítalos abajo y presiona Guardar mes.') }}">
                                                    {{ __('Capturado') }}
                                                </span>
                                            @endif
                                        </div>
                                        <input type="hidden" name="dias[{{ $dia }}][dia]" value="{{ $dia }}">
                                    </td>
                                    <td class="px-3 py-2">
                                        <x-text-input type="number" min="0" class="block w-28"
                                                      name="dias[{{ $dia }}][abordan]"
                                                      value="{{ $oldAbordan }}" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <x-text-input type="number" min="0" class="block w-28"
                                                      name="dias[{{ $dia }}][boletos_vendidos]"
                                                      value="{{ $oldBoletosVendidos }}" />
                                    </td>
                                    <td class="px-3 py-2">
                                        @if ($registro && ($puedeEliminar[$registro->id] ?? false))
                                            {{-- The row's "Eliminar" button can't own its own <form> here — this
                                                 whole table already lives inside the "Guardar mes" <form> above,
                                                 and HTML doesn't allow nested <form> elements (the browser would
                                                 silently drop the inner <form> tag, leaving its @method('DELETE')
                                                 field floating inside the OUTER form and turning a normal "Guardar
                                                 mes" submit into an unintended DELETE request). Instead, the button
                                                 references a real top-level <form> rendered as a sibling AFTER the
                                                 "Guardar mes" form closes, via the HTML5 form="" attribute. --}}
                                            <button type="submit" form="eliminar-registro-{{ $registro->id }}"
                                                    class="text-sm font-medium text-red-600 hover:text-red-800"
                                                    onclick="return confirm('{{ __('¿Eliminar este registro?') }}');">
                                                {{ __('Eliminar') }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>

                @error('dias')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror

                <div class="mt-4">
                    <x-primary-button type="submit">{{ __('Guardar mes') }}</x-primary-button>
                </div>
            </form>

            {{-- Real top-level "Eliminar" forms, one per deletable day — deliberately
                 rendered OUTSIDE (as siblings of, never nested inside) the "Guardar
                 mes" form above. Each row's button references its matching form here
                 via the HTML5 form="" attribute. Reuses the already-loaded $registros
                 collection, no additional queries. --}}
            @for ($dia = 1; $dia <= $diasEnElMes; $dia++)
                @php $registro = $registros->get($dia); @endphp
                @if ($registro && ($puedeEliminar[$registro->id] ?? false))
                    <form id="eliminar-registro-{{ $registro->id }}" method="POST"
                          action="{{ route('estadisticas.destroy', ['estacion' => $estacion->id, 'registro' => $registro->id]) }}"
                          class="hidden">
                        @csrf
                        @method('DELETE')
                    </form>
                @endif
            @endfor

        </div>
    </div>

    @if ($registros->isNotEmpty())
        <script>
            window.__pendingCharts = window.__pendingCharts || [];
            window.__pendingCharts.push({ canvasId: 'estadisticas-mensual-chart', config: @json($mensualChartConfig) });
        </script>
    @endif
</x-app-layout>
