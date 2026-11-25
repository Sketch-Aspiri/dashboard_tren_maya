@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var bool $puedeConsolidar */
    /** @var \Illuminate\Support\Collection<int, array{estacion: \App\Models\Estacion, abordan: int|null, boletos: int|null, capturados: int, faltan: int}> $tablero */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $n = fn ($v) => $v === null ? '—' : number_format((int) $v);
    $total = fn (string $campo) => $tablero->whereNotNull($campo)->isEmpty() ? null : $tablero->sum($campo);
    $abordan = $total('abordan');
    $boletos = $total('boletos');
    $conFaltantes = $tablero->filter(fn ($f) => $f['faltan'] > 0)->count();
    $maximo = max(1, (int) $tablero->max('abordan'));
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Flujo de pasajeros').' — '.$nombresMes[$mes].' '.$anio" :seccion="__('Flujo de pasajeros')" :grupo="__('Recursos financieros')"
                          :detalle="__('Abordan y boletos vendidos por estación')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.avisos />

            <div class="flex flex-wrap items-end justify-between gap-3">
                <x-insumos.periodo ruta="afluencia.index" :anio="$anio" :mes="$mes" />
                @if ($puedeConsolidar)
                    <a href="{{ route('afluencia.descargar', ['anio' => $anio]) }}" class="ins-btn-primario">{{ __('Descargar Excel del año') }}</a>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-kpi-card :label="__('Abordan en el mes')" :value="$n($abordan)" :hint="__('Suma de todas las estaciones.')" />
                <x-kpi-card :label="__('Boletos vendidos')" :value="$n($boletos)" :hint="__('Suma de todas las estaciones.')" />
                <x-kpi-card :label="__('Estaciones al corriente')" :value="($tablero->count() - $conFaltantes).' / '.$tablero->count()" :hint="__('Con todos los días capturados hasta ayer.')" />
                <x-kpi-card :label="__('Con días sin captura')" :value="$conFaltantes" :tono="$conFaltantes > 0 ? 'alerta' : null" :hint="__('Estaciones a las que les faltan días hasta ayer.')" />
            </div>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Flujo de pasajeros por estación') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Estación') }}</th>
                            <th scope="col" class="!text-right">{{ __('Abordan') }}</th>
                            <th scope="col" class="!text-right">{{ __('Boletos') }}</th>
                            <th scope="col" class="!text-right">{{ __('Días capturados') }}</th>
                            <th scope="col" class="!text-right">{{ __('Sin captura') }}</th>
                            <th scope="col" class="!text-right">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tablero as $fila)
                            <tr>
                                <th scope="row" class="px-3 py-2.5 text-left font-medium text-gray-900 sm:px-4">
                                    {{ $fila['estacion']->nombre }}
                                    <span class="mt-1 block h-1.5 rounded bg-brand-mist" aria-hidden="true">
                                        <span class="block h-1.5 rounded bg-brand-teal" style="width: {{ round($fila['abordan'] / $maximo * 100) }}%"></span>
                                    </span>
                                </th>
                                <td class="num">{{ $n($fila['abordan']) }}</td>
                                <td class="num">{{ $n($fila['boletos']) }}</td>
                                <td class="num">{{ $fila['capturados'] }}</td>
                                <td class="num {{ $fila['faltan'] > 0 ? 'text-amber-700' : '' }}">{{ $fila['faltan'] }}</td>
                                <td class="num">
                                    <a href="{{ route('afluencia.estacion', ['estacion' => $fila['estacion']->id, 'anio' => $anio, 'mes' => $mes]) }}"
                                       class="ins-btn-secundario !min-h-[36px] !px-3" aria-label="{{ __('Abrir') }} {{ $fila['estacion']->nombre }}">{{ __('Abrir') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="ins-ayuda">{{ __('Un día vacío es un día sin captura, no un cero; «—» indica que no hay ningún dato. El día de hoy no cuenta como faltante. La barra compara los abordajes de cada estación con la mayor.') }}</p>
        </div>
    </div>
</x-app-layout>
