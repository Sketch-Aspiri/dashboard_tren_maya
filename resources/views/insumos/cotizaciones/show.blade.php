@php
    /** @var \App\Models\Cotizacion $cotizacion */
    /** @var array<int, string> $meses */
    $dinero = fn (?float $monto) => $monto === null ? '—' : '$'.number_format($monto, 2);
    $porRevisar = $cotizacion->renglones->where('estatus', 'revisar')->count();
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Cotización').' '.$cotizacion->folio.' — '.$cotizacion->proveedor"
                          :volver="route('insumos.cotizaciones.index', ['anio' => $cotizacion->anio, 'mes' => $cotizacion->mes])"
                          :volver-texto="__('Cotizaciones de :mes :anio', ['mes' => $meses[$cotizacion->mes], 'anio' => $cotizacion->anio])" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.nav activo="cotizaciones" :anio="$cotizacion->anio" :mes="$cotizacion->mes" />

            <x-insumos.avisos />

            @if ($cotizacion->validacion !== 'OK')
                <div class="ins-aviso-alerta" role="status">
                    <span aria-hidden="true" class="mt-0.5 font-bold">!</span>
                    <div>
                        <p class="font-semibold">{{ __('Los números de esta cotización no cuadran: ningún renglón se actualiza solo hasta corregirla.') }}</p>
                        <p class="mt-1">{{ $cotizacion->validacion_detalle }}</p>
                    </div>
                </div>
            @elseif ($porRevisar > 0)
                <div class="ins-aviso-info" role="status">
                    <span aria-hidden="true" class="mt-0.5 font-bold">i</span>
                    <p>{{ trans_choice(':n renglón tiene un precio fuera del margen permitido: acéptalo o déjalo con su precio estimado.|:n renglones tienen un precio fuera del margen permitido: acéptalos o déjalos con su precio estimado.', $porRevisar, ['n' => $porRevisar]) }}</p>
                </div>
            @endif

            <section class="ins-card ins-card-pad">
                <dl class="grid grid-cols-2 gap-4 text-sm lg:grid-cols-5">
                    <div><dt class="ins-etiqueta">{{ __('Fecha') }}</dt><dd class="mt-0.5 text-gray-900">{{ $cotizacion->fecha?->format('d/m/Y') ?? '—' }}</dd></div>
                    <div><dt class="ins-etiqueta">{{ __('Subtotal') }}</dt><dd class="mt-0.5 tabular-nums text-gray-900">{{ $dinero($cotizacion->subtotal === null ? null : (float) $cotizacion->subtotal) }}</dd></div>
                    <div><dt class="ins-etiqueta">{{ __('IVA') }}</dt><dd class="mt-0.5 tabular-nums text-gray-900">{{ $dinero($cotizacion->iva === null ? null : (float) $cotizacion->iva) }}</dd></div>
                    <div><dt class="ins-etiqueta">{{ __('Total') }}</dt><dd class="mt-0.5 font-semibold tabular-nums text-gray-900">{{ $dinero($cotizacion->total === null ? null : (float) $cotizacion->total) }}</dd></div>
                    <div><dt class="ins-etiqueta">{{ __('Validación') }}</dt><dd class="mt-1"><x-insumos.estado :valor="$cotizacion->validacion === 'OK' ? 'OK' : 'ALERTA'" :texto="$cotizacion->validacion === 'OK' ? __('Cuadra') : __('No cuadra')" />
                        @if ($cotizacion->leida_con_ia)<span class="ins-chip-info ml-1">{{ __('Leída con IA') }}</span>@endif</dd></div>
                </dl>
                <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                    @if ($cotizacion->archivo_ruta)
                        <a href="{{ route('insumos.cotizaciones.archivo', $cotizacion) }}" target="_blank" rel="noopener" class="ins-btn-neutro">{{ __('Ver PDF') }}</a>
                    @endif
                    <a href="{{ route('insumos.cotizaciones.edit', $cotizacion) }}" class="ins-btn-secundario">{{ __('Editar') }}</a>
                    <x-confirmar-eliminar class="sm:ml-auto" :action="route('insumos.cotizaciones.destroy', $cotizacion)" :etiqueta="__('Eliminar cotización')"
                                          :pregunta="__('¿Eliminar? Las partidas volverán a su precio estimado.')" />
                </div>
            </section>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Renglones de la cotización') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('N°') }}</th>
                            <th scope="col">{{ __('Cotizado') }}</th>
                            <th scope="col">{{ __('Partida solicitada') }}</th>
                            <th scope="col" class="!text-right">{{ __('Cant. cot. / sol.') }}</th>
                            <th scope="col" class="!text-right">{{ __('P.U. cotizado') }}</th>
                            <th scope="col" class="!text-right">{{ __('P.U. estimado') }}</th>
                            <th scope="col">{{ __('Estatus') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cotizacion->renglones as $renglon)
                            @php
                                $partida = $renglon->partida;
                                $estimado = $partida ? ($partida->precio_estimado !== null ? (float) $partida->precio_estimado : ($partida->precio_unitario === null ? null : (float) $partida->precio_unitario)) : null;
                                $cotizado = $renglon->precio_unitario === null ? null : (float) $renglon->precio_unitario;
                                $variacion = $estimado && $cotizado !== null ? ($cotizado - $estimado) / $estimado * 100 : null;
                            @endphp
                            <tr class="{{ $renglon->estatus === 'revisar' ? '!bg-amber-50/70' : '' }}">
                                <td>{{ $renglon->numero }}</td>
                                <td class="min-w-[14rem]"><span class="fuerte">{{ $renglon->descripcion }}</span>@if ($renglon->clave)<span class="block font-mono text-xs text-gray-500">{{ $renglon->clave }}</span>@endif</td>
                                <td class="min-w-[12rem]">
                                    @if ($partida)
                                        <span class="block text-xs font-semibold text-gray-500">{{ $partida->solicitud->estacion->nombre }}</span>{{ $partida->descripcion }}
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="num">{{ (float) $renglon->cantidad }} / {{ $partida ? (float) $partida->cantidad : '—' }}</td>
                                <td class="num fuerte">{{ $dinero($cotizado) }}
                                    @if ($variacion !== null && abs($variacion) >= 0.5)
                                        <span class="block text-xs {{ $variacion > 0 ? 'text-red-700' : 'text-green-700' }}">{{ $variacion > 0 ? '+' : '' }}{{ number_format($variacion, 0) }} %</span>
                                    @endif
                                </td>
                                <td class="num">{{ $partida ? $dinero($estimado) : '—' }}</td>
                                <td>
                                    <x-insumos.estado :valor="$renglon->estatus" />
                                    @if ($renglon->motivo)<span class="mt-1 block text-xs text-gray-600">{{ $renglon->motivo }}@if ($renglon->motivo_detalle): {{ $renglon->motivo_detalle }}@endif</span>@endif
                                </td>
                                <td class="num">
                                    @if ($renglon->estatus === 'revisar' && $partida && $renglon->precio_unitario !== null)
                                        <form method="POST" action="{{ route('insumos.cotizaciones.aceptar', $renglon) }}">
                                            @csrf
                                            <button type="submit" class="ins-btn-primario !min-h-[36px] !px-3">{{ __('Aceptar precio') }}</button>
                                        </form>
                                    @elseif ($renglon->estatus === 'actualizado')
                                        <form method="POST" action="{{ route('insumos.cotizaciones.rechazar', $renglon) }}">
                                            @csrf
                                            <button type="submit" class="ins-enlace-peligro">{{ __('Rechazar') }}</button>
                                        </form>
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
