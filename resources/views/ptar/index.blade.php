@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var bool $puedeConsolidar */
    /** @var list<int> $capturables */
    /** @var \Illuminate\Support\Collection<int, array{planta: \App\Models\PtarPlanta, estacion: \App\Models\Estacion, registro: \App\Models\PtarRegistro|null, hallazgos_abiertos: int}> $tablero */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $total = $tablero->count();
    $registros = $tablero->pluck('registro')->filter();
    $enviados = $registros->filter(fn ($r) => $r->estado !== 'borrador')->count();
    $porRevisar = $registros->filter(fn ($r) => $r->estado === 'enviada')->count();
    $abiertos = $tablero->sum('hallazgos_abiertos');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Reporte de Estatus PTAR').' — '.$nombresMes[$mes].' '.$anio" :seccion="__('PTAR')" :grupo="__('Otros')"
                          :detalle="trans_choice(':n de :t planta envió su registro|:n de :t plantas enviaron su registro', $enviados, ['n' => $enviados, 't' => $total])" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.avisos />

            <div class="flex flex-wrap items-end justify-between gap-3">
                <x-insumos.periodo ruta="ptar.index" :anio="$anio" :mes="$mes" />
                <div class="flex flex-wrap items-center gap-3">
                    <a href="{{ route('ptar.hallazgos.index') }}" class="ins-btn-secundario">{{ __('Hallazgos y propuestas') }}</a>
                    @if ($puedeConsolidar)
                        <a href="{{ route('ptar.descargar', ['anio' => $anio, 'mes' => $mes]) }}" class="ins-btn-primario">{{ __('Descargar Excel') }}</a>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
                <x-kpi-card :label="__('Plantas que enviaron')" :value="$enviados.' / '.$total" :hint="__('Incluye enviadas, observadas y aprobadas.')" />
                <x-kpi-card :label="__('Por revisar')" :value="$porRevisar" :tono="$porRevisar > 0 ? 'alerta' : null" :hint="__('Enviadas que esperan a la Zona.')" />
                <x-kpi-card :label="__('Hallazgos abiertos')" :value="$abiertos" :tono="$abiertos > 0 ? 'alerta' : null" :hint="__('Problemáticas y propuestas sin cerrar.')" />
            </div>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('PTAR por estación') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('No.') }}</th>
                            <th scope="col">{{ __('Estación') }}</th>
                            <th scope="col">{{ __('Tramo') }}</th>
                            <th scope="col">{{ __('Modo') }}</th>
                            <th scope="col">{{ __('Situación') }}</th>
                            <th scope="col">{{ __('Estado') }}</th>
                            <th scope="col" class="!text-right">{{ __('Mant. vencido') }}</th>
                            <th scope="col" class="!text-right">{{ __('Hallazgos abiertos') }}</th>
                            <th scope="col" class="!text-right">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tablero as $fila)
                            <tr>
                                <td>{{ $fila['planta']->numero }}</td>
                                <th scope="row">{{ $fila['estacion']->nombre }}</th>
                                <td>{{ $fila['planta']->tramo ?? '—' }}</td>
                                <td>{{ $fila['registro']?->etiquetaModo() ?? '—' }}</td>
                                @php
                                    $chip = ['verde' => ['ins-chip-ok', __('Operación normal')], 'ambar' => ['ins-chip-alerta', __('Con atención')], 'rojo' => ['ins-chip-error', __('Con fallas')], 'gris' => ['ins-chip-neutro', __('Sin dato')]][$fila['semaforo']];
                                @endphp
                                <td><span class="{{ $chip[0] }}">{{ $chip[1] }}</span></td>
                                <td>{{ $fila['registro']?->etiquetaEstado() ?? __('Sin captura') }}</td>
                                <td class="!text-right">{{ $fila['vencidas'] }}</td>
                                <td class="!text-right">{{ $fila['hallazgos_abiertos'] }}</td>
                                <td class="!text-right">
                                    @if ($fila['registro'])
                                        <a href="{{ route('ptar.show', $fila['registro']) }}" class="ins-enlace">{{ __('Abrir') }}</a>
                                    @elseif (in_array($fila['estacion']->id, $capturables, true))
                                        <a href="{{ route('ptar.estacion', ['estacion' => $fila['estacion']->id, 'anio' => $anio, 'mes' => $mes]) }}" class="ins-enlace">{{ __('Capturar') }}</a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9">{{ __('Aún no hay plantas registradas.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
