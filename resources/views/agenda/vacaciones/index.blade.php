@php
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Vacacionista> $vacacionistas */
    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $trimestres = [1 => 'T1', 2 => 'T2', 3 => 'T3', 4 => 'T4'];
    // isoFormat('MMM') in Spanish yields "feb." — drop the dot for a cleaner range.
    $formatearFecha = fn ($fecha) => str_replace('.', '', $fecha->locale('es')->isoFormat('D MMM'));
    $formatearPeriodo = fn ($periodo) => $formatearFecha($periodo->fecha_inicio).' – '.$formatearFecha($periodo->fecha_termino);
    $hayFiltros = filled($filtros['q'] ?? null) || filled($filtros['estacion_id'] ?? null) || filled($filtros['mes'] ?? null);
    $selectClasses = 'min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('RR.HH.') }} — {{ __('Rol de vacaciones') }} {{ $anio }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            <form method="GET" action="{{ route('agenda.vacaciones.index') }}"
                  class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto] lg:items-end">
                <div>
                    <label for="q" class="sr-only">{{ __('Buscar') }}</label>
                    <input id="q" type="search" name="q" value="{{ $filtros['q'] ?? '' }}" maxlength="100"
                           placeholder="{{ __('Buscar por nombre, no. de empleado o puesto...') }}"
                           class="{{ $selectClasses }}">
                </div>

                <div>
                    <label for="estacion_id" class="sr-only">{{ __('Estación') }}</label>
                    <select id="estacion_id" name="estacion_id" class="{{ $selectClasses }}">
                        <option value="">{{ __('Todas las estaciones') }}</option>
                        @foreach ($estaciones as $estacion)
                            <option value="{{ $estacion->id }}" @selected((int) ($filtros['estacion_id'] ?? 0) === $estacion->id)>{{ $estacion->nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="mes" class="sr-only">{{ __('Mes') }}</label>
                    <select id="mes" name="mes" class="{{ $selectClasses }}">
                        <option value="">{{ __('Todo el año') }}</option>
                        @foreach ($meses as $numero => $nombre)
                            <option value="{{ $numero }}" @selected((int) ($filtros['mes'] ?? 0) === $numero)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="anio" class="sr-only">{{ __('Año') }}</label>
                    <select id="anio" name="anio" class="{{ $selectClasses }}">
                        @foreach ($anios ?: [$anio] as $anioDisponible)
                            <option value="{{ $anioDisponible }}" @selected($anioDisponible === $anio)>{{ $anioDisponible }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2">
                    <x-primary-button class="min-h-[44px]">{{ __('Filtrar') }}</x-primary-button>
                    @if ($hayFiltros)
                        <a href="{{ route('agenda.vacaciones.index', ['anio' => $anio]) }}"
                           class="inline-flex min-h-[44px] items-center rounded-md border border-brand-green/20 px-3 text-sm text-brand-green hover:bg-brand-mist">
                            {{ __('Limpiar') }}
                        </a>
                    @endif
                </div>
            </form>

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <!-- Desktop / tablet table (>= md) -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-brand-mist">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Empleado') }}</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Estación') }}</th>
                                <th scope="col" class="px-3 py-3 text-right text-xs font-medium uppercase text-brand-green">{{ __('Otorgados') }}</th>
                                @foreach ($trimestres as $etiqueta)
                                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ $etiqueta }}</th>
                                @endforeach
                                <th scope="col" class="px-3 py-3 text-right text-xs font-medium uppercase text-brand-green">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @forelse ($vacacionistas as $vacacionista)
                                @php $porTrimestre = $vacacionista->periodos->groupBy('trimestre'); @endphp
                                <tr class="align-top hover:bg-brand-mist/40">
                                    <td class="px-4 py-3 text-sm">
                                        @if ($vacacionista->empleado_id)
                                            <a href="{{ route('agenda.personal.show', $vacacionista->empleado_id) }}"
                                               class="font-medium text-brand-teal hover:text-brand-green-dark">{{ $vacacionista->nombre_completo }}</a>
                                        @else
                                            <span class="font-medium text-gray-900">{{ $vacacionista->nombre_completo }}</span>
                                        @endif
                                        <p class="text-xs text-gray-500">{{ $vacacionista->no_empleado }} · {{ $vacacionista->denominacion_puesto ?? '—' }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $vacacionista->estacion?->nombre ?? '—' }}</td>
                                    <td class="px-3 py-3 text-right text-sm text-gray-700">{{ $vacacionista->dias_otorgados ?? '—' }}</td>
                                    @foreach ($trimestres as $numero => $etiqueta)
                                        <td class="px-3 py-3 text-sm text-gray-700">
                                            @forelse ($porTrimestre->get($numero, []) as $periodo)
                                                <p class="whitespace-nowrap">{{ $formatearPeriodo($periodo) }} <span class="text-xs text-gray-400">({{ $periodo->dias_solicitados }})</span></p>
                                            @empty
                                                <span class="text-gray-300">—</span>
                                            @endforelse
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-3 text-right text-sm font-semibold text-brand-green">{{ $vacacionista->total_dias }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ 4 + count($trimestres) }}" class="px-4 py-6 text-center text-sm text-gray-400">{{ __('Sin resultados.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Mobile stacked cards (< md) -->
                <div class="divide-y divide-gray-200 md:hidden">
                    @forelse ($vacacionistas as $vacacionista)
                        <div class="px-4 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900">{{ $vacacionista->nombre_completo }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $vacacionista->denominacion_puesto ?? '—' }}</p>
                                    <p class="text-xs text-gray-500">{{ $vacacionista->estacion?->nombre ?? '—' }} · {{ $vacacionista->no_empleado }}</p>
                                </div>
                                <span class="inline-flex shrink-0 rounded-full bg-brand-mist px-2 py-1 text-xs font-semibold text-brand-green">
                                    {{ $vacacionista->total_dias }} / {{ $vacacionista->dias_otorgados ?? '—' }} {{ __('días') }}
                                </span>
                            </div>
                            <ul class="mt-3 space-y-1 text-xs text-gray-700">
                                @forelse ($vacacionista->periodos as $periodo)
                                    <li>
                                        <span class="font-medium text-brand-green">T{{ $periodo->trimestre }}</span>
                                        {{ $formatearPeriodo($periodo) }}
                                        <span class="text-gray-400">({{ $periodo->dias_solicitados }})</span>
                                    </li>
                                @empty
                                    <li class="text-gray-400">{{ __('Sin periodos registrados.') }}</li>
                                @endforelse
                            </ul>
                        </div>
                    @empty
                        <p class="px-4 py-6 text-center text-sm text-gray-400">{{ __('Sin resultados.') }}</p>
                    @endforelse
                </div>

                @if ($vacacionistas->total() > 0)
                    <div class="flex flex-col gap-3 border-t border-gray-200 bg-brand-mist/50 px-4 py-3 text-sm text-gray-600 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:px-6">
                        <span>{{ __('Mostrando') }} {{ $vacacionistas->firstItem() }}–{{ $vacacionistas->lastItem() }} {{ __('de') }} {{ $vacacionistas->total() }}</span>

                        @if ($vacacionistas->hasPages())
                            <div class="flex items-center justify-between gap-2 sm:justify-start">
                                @if ($vacacionistas->onFirstPage())
                                    <span class="inline-flex min-h-[44px] cursor-not-allowed items-center rounded-md border border-brand-green/20 px-3 py-1 text-brand-green opacity-40">{{ __('Anterior') }}</span>
                                @else
                                    <a href="{{ $vacacionistas->previousPageUrl() }}" rel="prev"
                                       class="inline-flex min-h-[44px] items-center rounded-md border border-brand-green/20 px-3 py-1 text-brand-green hover:bg-white">{{ __('Anterior') }}</a>
                                @endif

                                <span>{{ __('Página') }} {{ $vacacionistas->currentPage() }} {{ __('de') }} {{ $vacacionistas->lastPage() }}</span>

                                @if ($vacacionistas->hasMorePages())
                                    <a href="{{ $vacacionistas->nextPageUrl() }}" rel="next"
                                       class="inline-flex min-h-[44px] items-center rounded-md border border-brand-green/20 px-3 py-1 text-brand-green hover:bg-white">{{ __('Siguiente') }}</a>
                                @else
                                    <span class="inline-flex min-h-[44px] cursor-not-allowed items-center rounded-md border border-brand-green/20 px-3 py-1 text-brand-green opacity-40">{{ __('Siguiente') }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <p class="text-xs text-gray-500">
                {{ __('Los días entre paréntesis son días hábiles (lunes a viernes) de cada periodo.') }}
            </p>
        </div>
    </div>
</x-app-layout>
