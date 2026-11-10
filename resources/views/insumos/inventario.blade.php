@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Estacion> $estaciones */
    /** @var \App\Models\Estacion|null $estacion */
    /** @var \Illuminate\Support\Collection<int, \App\Models\InventarioEstacion> $filas */
    /** @var array<string, int> $conteo */
    $etiquetas = ['HERRAMIENTA' => __('Herramienta'), 'EPP' => __('Equipo de protección'), 'CONSUMIBLE' => __('Consumibles entregados')];
    $mesTexto = function (?string $anioMes) {
        if (! $anioMes) {
            return '—';
        }
        [$a, $m] = explode('-', $anioMes);
        $nombres = [1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic'];

        return ($nombres[(int) $m] ?? $m).' '.$a;
    };
    $filtrado = $tipo !== null || $q !== '';
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Inventario').($estacion ? ' — '.$estacion->nombre : '')"
                          :detalle="__('Según los resguardos de agosto 2025 a octubre 2026')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.nav activo="inventario" />

            <form method="GET" action="{{ route('insumos.inventario.index') }}" class="space-y-4">
                @if ($estaciones->count() > 1)
                    <div>
                        <label for="estacion-inv" class="ins-etiqueta">{{ __('Estación') }}</label>
                        <select id="estacion-inv" name="estacion" class="ins-campo mt-1 sm:w-72" onchange="this.form.submit()">
                            @foreach ($estaciones as $e)<option value="{{ $e->id }}" @selected($estacion && $e->id === $estacion->id)>{{ $e->nombre }}</option>@endforeach
                        </select>
                    </div>
                @elseif ($estacion)
                    <input type="hidden" name="estacion" value="{{ $estacion->id }}">
                @endif

                {{-- Tipo como pestañas con su conteo; un clic filtra. --}}
                <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Tipo') }}">
                    <button type="submit" name="tipo" value="" class="{{ $tipo === null ? 'ins-btn-primario' : 'ins-btn-neutro' }} !min-h-[40px]" @if ($tipo === null) aria-pressed="true" @endif>
                        {{ __('Todo') }} <span class="opacity-75">{{ array_sum($conteo) }}</span>
                    </button>
                    @foreach ($etiquetas as $valor => $texto)
                        <button type="submit" name="tipo" value="{{ $valor }}" class="{{ $tipo === $valor ? 'ins-btn-primario' : 'ins-btn-neutro' }} !min-h-[40px]" @if ($tipo === $valor) aria-pressed="true" @endif>
                            {{ $texto }} <span class="opacity-75">{{ $conteo[$valor] ?? 0 }}</span>
                        </button>
                    @endforeach
                    <div class="flex w-full gap-2 sm:ml-auto sm:w-auto" role="search">
                        <label for="q-inv" class="sr-only">{{ __('Buscar') }}</label>
                        <input id="q-inv" name="q" type="search" class="ins-campo sm:w-64" value="{{ $q }}" maxlength="100" placeholder="{{ __('Buscar artículo…') }}">
                        <button type="submit" name="tipo" value="{{ $tipo }}" class="ins-btn-neutro">{{ __('Buscar') }}</button>
                    </div>
                </div>
            </form>

            <p class="ins-ayuda">
                {{ __('La herramienta y el equipo de protección cuentan como existencia; de los consumibles solo importa la última entrega. Si una estación pide algo que ya tiene, o que se le entregó en los últimos :m meses, su solicitud lo marca y debe explicarlo en la justificación.', ['m' => $mesesConsumibles]) }}
            </p>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Inventario de la estación') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Artículo') }}</th>
                            <th scope="col">{{ __('Tipo') }}</th>
                            <th scope="col" class="!text-right">{{ __('Cantidad') }}</th>
                            <th scope="col">{{ __('Unidad') }}</th>
                            <th scope="col">{{ __('Desde') }}</th>
                            <th scope="col">{{ __('Última entrega') }}</th>
                            <th scope="col">{{ __('Partida') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            <tr>
                                <td class="fuerte min-w-[16rem]">{{ $fila->descripcion }}</td>
                                <td><x-insumos.estado :valor="$fila->tipo" /></td>
                                <td class="num fuerte">{{ $fila->cantidad + 0 }}</td>
                                <td>{{ $fila->unidad ?: '—' }}</td>
                                <td class="whitespace-nowrap">{{ $mesTexto($fila->primer_mes) }}</td>
                                <td class="whitespace-nowrap">{{ $mesTexto($fila->ultimo_mes) }}</td>
                                <td>{!! $fila->partida_presupuestal ? e($fila->partida_presupuestal) : '<span class="text-gray-400">—</span>' !!}</td>
                            </tr>
                        @empty
                            <x-insumos.vacio columnas="7" :titulo="$filtrado ? __('Nada coincide con este filtro.') : __('Esta estación no tiene renglones de inventario.')">
                                @if ($filtrado)<a href="{{ route('insumos.inventario.index', ['estacion' => $estacion?->id]) }}" class="font-medium text-brand-teal underline">{{ __('Ver todo el inventario') }}</a>@endif
                            </x-insumos.vacio>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
