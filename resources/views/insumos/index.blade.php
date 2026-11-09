@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var \Illuminate\Support\Collection<int, array{estacion: \App\Models\Estacion, solicitud: \App\Models\SolicitudInsumo|null}> $tablero */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $etiquetas = [
        'borrador' => ['Borrador', 'bg-gray-100 text-gray-700'],
        'enviada' => ['Enviada', 'bg-brand-mist text-brand-green'],
        'observada' => ['Observada', 'bg-amber-100 text-amber-800'],
        'aprobada' => ['Aprobada', 'bg-green-100 text-green-800'],
    ];
    $enviadas = $tablero->filter(fn ($fila) => $fila['solicitud'] && $fila['solicitud']->estado !== 'borrador')->count();
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Recursos materiales') }} — {{ __('Solicitud de insumos') }} {{ $nombresMes[$mes] }} {{ $anio }}
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
                <form method="GET" action="{{ route('insumos.index') }}" class="flex flex-wrap items-end gap-2">
                    <div>
                        <x-input-label for="mes-insumos" :value="__('Mes')" />
                        <select id="mes-insumos" name="mes" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                            @foreach ($nombresMes as $numero => $nombre)
                                <option value="{{ $numero }}" @selected($numero === $mes)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="max-w-xs">
                        <x-input-label for="anio-insumos" :value="__('Año')" />
                        <x-text-input id="anio-insumos" name="anio" type="number" class="block mt-1 w-full"
                                      value="{{ $anio }}" min="2000" max="2100" />
                    </div>
                    <x-secondary-button type="submit">{{ __('Ver mes') }}</x-secondary-button>
                </form>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-kpi-card :label="__('Estaciones que enviaron')" :value="$enviadas.' / '.$tablero->count()"
                            :hint="__('Incluye enviadas, observadas y aprobadas.')" />
                <x-kpi-card :label="__('Aprobadas')" :value="$tablero->filter(fn ($f) => $f['solicitud']?->estado === 'aprobada')->count()"
                            :hint="__('Listas para el consolidado.')" />
                <x-kpi-card :label="__('Sin iniciar')" :value="$tablero->filter(fn ($f) => $f['solicitud'] === null)->count()"
                            :hint="__('Aún no abren su solicitud del mes.')" />
            </div>

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-brand-mist text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                        <tr>
                            <th class="px-4 py-3">{{ __('Estación') }}</th>
                            <th class="px-4 py-3">{{ __('Estado') }}</th>
                            <th class="px-4 py-3">{{ __('Enviada') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($tablero as $fila)
                            @php
                                $solicitud = $fila['solicitud'];
                                [$texto, $clase] = $solicitud ? $etiquetas[$solicitud->estado] : ['Sin iniciar', 'bg-red-50 text-red-700'];
                            @endphp
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $fila['estacion']->nombre }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $clase }}">{{ __($texto) }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ $solicitud?->enviada_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($solicitud)
                                        <a href="{{ route('insumos.show', $solicitud) }}" class="text-brand-teal hover:underline">
                                            {{ in_array($solicitud->estado, ['enviada', 'observada'], true) ? __('Revisar') : __('Ver') }}
                                        </a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
