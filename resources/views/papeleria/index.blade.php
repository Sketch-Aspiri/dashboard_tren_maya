@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var bool $puedeConsolidar */
    /** @var \Illuminate\Support\Collection<int, array{estacion: \App\Models\Estacion, solicitud: \App\Models\SolicitudPapeleria|null}> $tablero */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $total = $tablero->count();
    $enviadas = $tablero->filter(fn ($f) => $f['solicitud'] && $f['solicitud']->estado !== 'borrador')->count();
    $aprobadas = $tablero->filter(fn ($f) => $f['solicitud']?->estaAprobada())->count();
    $porRevisar = $tablero->filter(fn ($f) => $f['solicitud']?->estado === 'enviada')->count();
    $sinIniciar = $tablero->filter(fn ($f) => $f['solicitud'] === null)->count();
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Solicitud de papelería').' — '.$nombresMes[$mes].' '.$anio" :seccion="__('Papelería')"
                          :detalle="trans_choice(':n de :t estación envió su solicitud|:n de :t estaciones enviaron su solicitud', $enviadas, ['n' => $enviadas, 't' => $total])" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-papeleria.nav activo="solicitudes" :anio="$anio" :mes="$mes" />

            <x-insumos.avisos />

            <div class="flex flex-wrap items-end justify-between gap-3">
                <x-insumos.periodo ruta="papeleria.index" :anio="$anio" :mes="$mes" />
                <div class="flex flex-wrap items-center gap-3">
                    @if ($porRevisar > 0)
                        <p class="ins-chip-info">{{ trans_choice(':n solicitud espera tu revisión|:n solicitudes esperan tu revisión', $porRevisar, ['n' => $porRevisar]) }}</p>
                    @endif
                    @if ($puedeConsolidar)
                        <a href="{{ route('papeleria.descargar', ['anio' => $anio, 'mes' => $mes]) }}" class="ins-btn-primario">{{ __('Descargar Excel (formato oficial)') }}</a>
                    @endif
                </div>
            </div>

            @if ($puedeConsolidar)
                <p class="ins-ayuda">{{ __('El Excel incluye solo las solicitudes aprobadas de :mes :anio.', ['mes' => $nombresMes[$mes], 'anio' => $anio]) }}</p>
            @endif

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-kpi-card :label="__('Estaciones que enviaron')" :value="$enviadas.' / '.$total" :hint="__('Incluye enviadas, observadas y aprobadas.')" />
                <x-kpi-card :label="__('Por revisar')" :value="$porRevisar" :tono="$porRevisar > 0 ? 'alerta' : null" :hint="__('Enviadas que esperan a la Zona.')" />
                <x-kpi-card :label="__('Aprobadas')" :value="$aprobadas" :hint="__('Listas para el Excel.')" />
                <x-kpi-card :label="__('Sin iniciar')" :value="$sinIniciar" :tono="$sinIniciar > 0 ? 'peligro' : null" :hint="__('Aún no abren su solicitud del mes.')" />
            </div>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Solicitudes de papelería por estación') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Estación') }}</th>
                            <th scope="col">{{ __('Estado') }}</th>
                            <th scope="col" class="!text-right">{{ __('Artículos') }}</th>
                            <th scope="col">{{ __('Enviada') }}</th>
                            <th scope="col" class="!text-right">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tablero as $fila)
                            @php
                                $solicitud = $fila['solicitud'];
                                $porAtender = $solicitud && $solicitud->estado === 'enviada' && $puedeConsolidar;
                            @endphp
                            <tr>
                                <th scope="row" class="px-3 py-2.5 text-left font-medium text-gray-900 sm:px-4">{{ $fila['estacion']->nombre }}</th>
                                <td><x-insumos.estado :valor="$solicitud?->estado ?? 'sin_iniciar'" /></td>
                                <td class="num">{{ $solicitud?->partidas_count ?? '—' }}</td>
                                <td class="whitespace-nowrap text-gray-600">{{ $solicitud?->enviada_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="num">
                                    @if ($solicitud)
                                        <a href="{{ route('papeleria.show', $solicitud) }}" class="{{ $porAtender ? 'ins-btn-primario' : 'ins-btn-secundario' }} !min-h-[36px] !px-3"
                                           aria-label="{{ ($porAtender ? __('Revisar') : __('Ver')).' '.$fila['estacion']->nombre }}">
                                            {{ $porAtender ? __('Revisar') : __('Ver') }}
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
