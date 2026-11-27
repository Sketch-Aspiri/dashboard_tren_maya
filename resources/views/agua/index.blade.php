@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var bool $puedeConsolidar */
    /** @var \Illuminate\Support\Collection<int, array{estacion: \App\Models\Estacion, registro: \App\Models\AguaRegistro|null}> $tablero */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $dinero = fn ($n) => '$'.number_format((float) $n, 2);
    $total = $tablero->count();
    $registros = $tablero->pluck('registro')->filter();
    $enviados = $registros->filter(fn ($r) => $r->estado !== 'borrador')->count();
    $porRevisar = $registros->filter(fn ($r) => $r->estado === 'enviada')->count();
    $comprado = $registros->sum(fn ($r) => $r->totalComprado());
    $cuota = $registros->sum(fn ($r) => $r->cuota());
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Registro de agua').' — '.$nombresMes[$mes].' '.$anio" :seccion="__('Agua')"
                          :detalle="trans_choice(':n de :t estación envió su registro|:n de :t estaciones enviaron su registro', $enviados, ['n' => $enviados, 't' => $total])" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.avisos />

            <div class="flex flex-wrap items-end justify-between gap-3">
                <x-insumos.periodo ruta="agua.index" :anio="$anio" :mes="$mes" />
                <div class="flex flex-wrap items-center gap-3">
                    @if ($porRevisar > 0)
                        <p class="ins-chip-info">{{ trans_choice(':n registro espera tu revisión|:n registros esperan tu revisión', $porRevisar, ['n' => $porRevisar]) }}</p>
                    @endif
                    @if ($puedeConsolidar)
                        <a href="{{ route('agua.descargar', ['anio' => $anio, 'mes' => $mes]) }}" class="ins-btn-primario">{{ __('Descargar Excel') }}</a>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-kpi-card :label="__('Estaciones que enviaron')" :value="$enviados.' / '.$total" :hint="__('Incluye enviadas, observadas y aprobadas.')" />
                <x-kpi-card :label="__('Por revisar')" :value="$porRevisar" :tono="$porRevisar > 0 ? 'alerta' : null" :hint="__('Enviadas que esperan a la Zona.')" />
                <x-kpi-card :label="__('Comprado en el mes')" :value="$dinero($comprado)" :hint="__('Suma de las compras registradas.')" />
                <x-kpi-card :label="__('Cuota asignada')" :value="$dinero($cuota)" :hint="__('Consumo mensual × costo unitario.')" />
            </div>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Registro de agua por estación') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Estación') }}</th>
                            <th scope="col">{{ __('Estado') }}</th>
                            <th scope="col" class="!text-right">{{ __('Compras') }}</th>
                            <th scope="col" class="!text-right">{{ __('Comprado') }}</th>
                            <th scope="col" class="!text-right">{{ __('Cuota') }}</th>
                            <th scope="col" class="!text-right">{{ __('Saldo') }}</th>
                            <th scope="col" class="!text-right">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tablero as $fila)
                            @php
                                $r = $fila['registro'];
                                $porAtender = $r && $r->estado === 'enviada' && $puedeConsolidar;
                            @endphp
                            <tr>
                                <th scope="row" class="px-3 py-2.5 text-left font-medium text-gray-900 sm:px-4">{{ $fila['estacion']->nombre }}</th>
                                <td><x-insumos.estado :valor="$r?->estado ?? 'sin_iniciar'" /></td>
                                <td class="num">{{ $r ? $r->compras->count() : '—' }}</td>
                                <td class="num">{{ $r ? $dinero($r->totalComprado()) : '—' }}</td>
                                <td class="num">{{ $r ? $dinero($r->cuota()) : '—' }}</td>
                                <td class="num {{ $r && $r->saldo() > 0 ? 'text-amber-700' : '' }}">{{ $r ? $dinero($r->saldo()) : '—' }}@if ($r && $r->saldo() != 0)<span class="sr-only"> ({{ $r->saldo() > 0 ? __('reintegro') : __('pendiente de entrega') }})</span>@endif</td>
                                <td class="num">
                                    @if ($r)
                                        <a href="{{ route('agua.show', $r) }}" class="{{ $porAtender ? 'ins-btn-primario' : 'ins-btn-secundario' }} !min-h-[36px] !px-3"
                                           aria-label="{{ ($porAtender ? __('Revisar') : __('Ver')).' '.$fila['estacion']->nombre }}">{{ $porAtender ? __('Revisar') : __('Ver') }}</a>
                                    @elseif ($puedeConsolidar)
                                        <a href="{{ route('agua.estacion', ['estacion' => $fila['estacion']->id, 'anio' => $anio, 'mes' => $mes]) }}" class="ins-btn-secundario !min-h-[36px] !px-3"
                                           aria-label="{{ __('Abrir') }} {{ $fila['estacion']->nombre }}">{{ __('Abrir') }}</a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="ins-ayuda">{{ __('Saldo = comprado − cuota: negativo es pendiente de entrega; positivo, reintegro.') }}</p>
        </div>
    </div>
</x-app-layout>
