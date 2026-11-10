@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Estacion> $estaciones */
    /** @var \App\Models\Estacion|null $estacion */
    /** @var \Illuminate\Support\Collection<int, \App\Models\InventarioEstacion> $filas */
    /** @var array<string, int> $conteo */
    $campo = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal text-sm';
    $etiquetas = ['HERRAMIENTA' => __('Herramienta'), 'EPP' => __('Equipo de protección'), 'CONSUMIBLE' => __('Consumible entregado')];
    $estilos = ['HERRAMIENTA' => 'bg-blue-100 text-blue-800', 'EPP' => 'bg-green-100 text-green-800', 'CONSUMIBLE' => 'bg-gray-100 text-gray-700'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Inventario de estaciones') }}@if ($estacion) — {{ $estacion->nombre }}@endif
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="text-sm text-gray-600">
                    {{ __('Lo que cada estación tiene según sus resguardos (agosto 2025 a octubre 2026). La herramienta y el equipo de protección cuentan como existencia; de los consumibles solo importa la última entrega: si una estación pide algo que ya tiene, o que se le entregó en los últimos :m meses, la solicitud lo marca y debe explicarse en la justificación.', ['m' => $mesesConsumibles]) }}
                </p>

                <form method="GET" action="{{ route('insumos.inventario.index') }}" class="mt-4 flex flex-wrap items-end gap-3">
                    @if ($estaciones->count() > 1)
                        <div>
                            <x-input-label for="estacion-inv" :value="__('Estación')" />
                            <select id="estacion-inv" name="estacion" class="{{ $campo }}" onchange="this.form.submit()">
                                @foreach ($estaciones as $e)<option value="{{ $e->id }}" @selected($estacion && $e->id === $estacion->id)>{{ $e->nombre }}</option>@endforeach
                            </select>
                        </div>
                    @elseif ($estacion)
                        <input type="hidden" name="estacion" value="{{ $estacion->id }}">
                    @endif
                    <div>
                        <x-input-label for="tipo-inv" :value="__('Tipo')" />
                        <select id="tipo-inv" name="tipo" class="{{ $campo }}">
                            <option value="">{{ __('Todos') }}</option>
                            @foreach ($etiquetas as $valor => $texto)<option value="{{ $valor }}" @selected($tipo === $valor)>{{ $texto }} ({{ $conteo[$valor] ?? 0 }})</option>@endforeach
                        </select>
                    </div>
                    <div class="grow max-w-sm">
                        <x-input-label for="q-inv" :value="__('Buscar')" />
                        <x-text-input id="q-inv" name="q" type="text" class="block mt-1 w-full" value="{{ $q }}" maxlength="100" />
                    </div>
                    <x-secondary-button type="submit">{{ __('Filtrar') }}</x-secondary-button>
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <table class="min-w-full text-sm">
                    <thead class="bg-brand-mist text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                        <tr>
                            <th class="px-4 py-3">{{ __('Descripción') }}</th>
                            <th class="px-4 py-3">{{ __('Tipo') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Cantidad') }}</th>
                            <th class="px-4 py-3">{{ __('Unidad') }}</th>
                            <th class="px-4 py-3">{{ __('Primer mes') }}</th>
                            <th class="px-4 py-3">{{ __('Último mes') }}</th>
                            <th class="px-4 py-3">{{ __('Partida') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($filas as $fila)
                            <tr>
                                <td class="px-4 py-2 text-gray-900">{{ $fila->descripcion }}</td>
                                <td class="px-4 py-2"><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $estilos[$fila->tipo] ?? '' }}">{{ $etiquetas[$fila->tipo] ?? $fila->tipo }}</span></td>
                                <td class="px-4 py-2 text-right">{{ $fila->cantidad + 0 }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $fila->unidad ?: '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $fila->primer_mes ?: '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $fila->ultimo_mes ?: '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $fila->partida_presupuestal ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">{{ __('No hay renglones de inventario para este filtro.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>
                <a href="{{ route('insumos.index') }}" class="text-sm text-gray-600 hover:text-brand-green">← {{ __('Volver') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
