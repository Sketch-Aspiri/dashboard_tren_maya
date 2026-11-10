@php
    /** @var int $anio */
    /** @var int $mes */
    /** @var bool $puedeConsolidar */
    /** @var array<string, mixed>|null $consolidado */
    $dinero = fn (float $monto) => '$'.number_format($monto, 2);
    /** @var \Illuminate\Support\Collection<int, array{estacion: \App\Models\Estacion, solicitud: \App\Models\SolicitudInsumo|null}> $tablero */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $total = $tablero->count();
    $enviadas = $tablero->filter(fn ($fila) => $fila['solicitud'] && $fila['solicitud']->estado !== 'borrador')->count();
    $aprobadas = $tablero->filter(fn ($f) => $f['solicitud']?->estaAprobada())->count();
    $porRevisar = $tablero->filter(fn ($f) => $f['solicitud']?->estado === 'enviada')->count();
    $sinIniciar = $tablero->filter(fn ($f) => $f['solicitud'] === null)->count();
    $usado = $consolidado && $consolidado['tope'] > 0 ? min(100, max(0, $consolidado['total'] / $consolidado['tope'] * 100)) : 0;
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Solicitud de insumos').' — '.$nombresMes[$mes].' '.$anio"
                          :detalle="trans_choice(':n de :t estación envió su solicitud|:n de :t estaciones enviaron su solicitud', $enviadas, ['n' => $enviadas, 't' => $total])" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.nav activo="solicitudes" :anio="$anio" :mes="$mes" />

            <x-insumos.avisos />

            <div class="flex flex-wrap items-end justify-between gap-3">
                <x-insumos.periodo ruta="insumos.index" :anio="$anio" :mes="$mes" />
                @if ($porRevisar > 0)
                    <p class="ins-chip-info">{{ trans_choice(':n solicitud espera tu revisión|:n solicitudes esperan tu revisión', $porRevisar, ['n' => $porRevisar]) }}</p>
                @endif
            </div>

            @if ($puedeConsolidar && $consolidado)
                {{-- Fondo de la zona: cuánto llevan aprobado las estaciones frente al tope mensual. --}}
                <section class="ins-card ins-card-pad" aria-labelledby="fondo-titulo">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                        <h3 id="fondo-titulo" class="ins-titulo">{{ __('Fondo rotatorio del mes') }}</h3>
                        <p class="text-sm text-gray-600">
                            <span class="font-semibold text-gray-900">{{ $dinero($consolidado['total']) }}</span>
                            {{ __('aprobado de') }} {{ $dinero($consolidado['tope']) }}
                        </p>
                    </div>
                    <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-gray-100" role="img"
                         aria-label="{{ __(':p % del fondo aprobado', ['p' => round($usado)]) }}">
                        <div class="h-full rounded-full {{ $consolidado['pendiente'] < 0 ? 'bg-red-500' : ($usado > 85 ? 'bg-amber-500' : 'bg-brand-teal') }}" style="width: {{ $usado }}%"></div>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                        <div><dt class="ins-etiqueta">{{ __('Subtotal') }}</dt><dd class="mt-0.5 font-semibold tabular-nums text-gray-900">{{ $dinero($consolidado['subtotal']) }}</dd></div>
                        <div><dt class="ins-etiqueta">{{ __('IVA') }}</dt><dd class="mt-0.5 font-semibold tabular-nums text-gray-900">{{ $dinero($consolidado['iva']) }}</dd></div>
                        <div><dt class="ins-etiqueta">{{ __('Total aprobado con IVA') }}</dt><dd class="mt-0.5 font-semibold tabular-nums text-gray-900">{{ $dinero($consolidado['total']) }}</dd></div>
                        <div>
                            <dt class="ins-etiqueta">{{ __('Pendiente por ejercer') }}</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums {{ $consolidado['pendiente'] < 0 ? 'text-red-700' : 'text-brand-green' }}">{{ $dinero($consolidado['pendiente']) }}</dd>
                        </div>
                    </dl>
                    @if ($consolidado['pendiente'] < 0)
                        <p class="mt-3 text-sm font-medium text-red-700">{{ __('Las solicitudes aprobadas exceden el fondo.') }}</p>
                    @endif
                </section>
            @endif

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-kpi-card :label="__('Estaciones que enviaron')" :value="$enviadas.' / '.$total"
                            :hint="__('Incluye enviadas, observadas y aprobadas.')" />
                <x-kpi-card :label="__('Por revisar')" :value="$porRevisar" :tono="$porRevisar > 0 ? 'alerta' : null"
                            :hint="__('Enviadas que esperan a la Zona.')" />
                <x-kpi-card :label="__('Aprobadas')" :value="$aprobadas"
                            :hint="__('Listas para el consolidado.')" />
                <x-kpi-card :label="__('Sin iniciar')" :value="$sinIniciar" :tono="$sinIniciar > 0 ? 'peligro' : null"
                            :hint="__('Aún no abren su solicitud del mes.')" />
            </div>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Solicitudes por estación') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Estación') }}</th>
                            <th scope="col">{{ __('Estado') }}</th>
                            <th scope="col">{{ __('Enviada') }}</th>
                            <th scope="col" class="!text-right">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tablero as $fila)
                            @php
                                $solicitud = $fila['solicitud'];
                                $porAtender = $solicitud && in_array($solicitud->estado, ['enviada', 'observada'], true);
                            @endphp
                            <tr>
                                <th scope="row" class="px-3 py-2.5 text-left font-medium text-gray-900 sm:px-4">{{ $fila['estacion']->nombre }}</th>
                                <td><x-insumos.estado :valor="$solicitud?->estado ?? 'sin_iniciar'" /></td>
                                <td class="whitespace-nowrap text-gray-600">{{ $solicitud?->enviada_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="num">
                                    @if ($solicitud)
                                        <a href="{{ route('insumos.show', $solicitud) }}" class="{{ $solicitud->estado === 'enviada' ? 'ins-btn-primario' : 'ins-btn-secundario' }} !min-h-[36px] !px-3"
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

            @if ($puedeConsolidar && $consolidado)
                <form id="consolidado" method="GET" action="{{ route('insumos.consolidado') }}" class="ins-card ins-card-pad scroll-mt-4">
                    <input type="hidden" name="anio" value="{{ $anio }}">
                    <input type="hidden" name="mes" value="{{ $mes }}">
                    <h3 class="ins-titulo">{{ __('Consolidado del mes (Excel)') }}</h3>
                    <p class="ins-ayuda mt-1">{{ __('Solo incluye las solicitudes aprobadas de :mes :anio.', ['mes' => $nombresMes[$mes], 'anio' => $anio]) }}</p>
                    <div class="mt-4 flex flex-wrap items-end gap-3">
                        <div class="w-full sm:w-64">
                            <x-input-label for="oficio" :value="__('Número de oficio (para el nombre del ANEXO)')" />
                            <input id="oficio" name="oficio" type="text" class="ins-campo mt-1" maxlength="20" pattern="[A-Za-z0-9_\-]+" placeholder="1918">
                        </div>
                        <button type="submit" name="tipo" value="anexo" class="ins-btn-primario">{{ __('Descargar ANEXO') }}</button>
                        <button type="submit" name="tipo" value="listado" class="ins-btn-secundario">{{ __('Listado por producto') }}</button>
                        <button type="submit" name="tipo" value="control" class="ins-btn-secundario">{{ __('Control del filtro') }}</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
