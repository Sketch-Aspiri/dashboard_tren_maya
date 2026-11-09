@php
    /** @var \App\Models\Cotizacion $cotizacion */
    /** @var array<int, string> $meses */
    $dinero = fn (?float $monto) => $monto === null ? '—' : '$'.number_format($monto, 2);
    $estilos = [
        'actualizado' => ['Actualizado', 'bg-green-100 text-green-800'],
        'revisar' => ['Revisar', 'bg-amber-100 text-amber-800'],
        'sin_vincular' => ['Fuera del documento', 'bg-gray-100 text-gray-700'],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Cotización') }} {{ $cotizacion->proveedor }} {{ $cotizacion->folio }} — {{ $meses[$cotizacion->mes] }} {{ $cotizacion->anio }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-xl border border-brand-green/10 bg-brand-mist p-4 text-sm text-brand-green">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            @if ($cotizacion->validacion !== 'OK')
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    <p class="font-semibold">{{ __('Los números de esta cotización no cuadran: ningún renglón se actualiza solo hasta corregirla.') }}</p>
                    <p class="mt-1">{{ $cotizacion->validacion_detalle }}</p>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3 rounded-xl border border-brand-green/10 bg-white p-4 text-sm text-gray-700 shadow-sm shadow-brand-green/5">
                <span>{{ __('Fecha') }}: {{ $cotizacion->fecha?->format('d/m/Y') ?? '—' }}</span>
                <span>{{ __('Subtotal') }}: {{ $dinero($cotizacion->subtotal === null ? null : (float) $cotizacion->subtotal) }}</span>
                <span>{{ __('IVA') }}: {{ $dinero($cotizacion->iva === null ? null : (float) $cotizacion->iva) }}</span>
                <span>{{ __('Total') }}: {{ $dinero($cotizacion->total === null ? null : (float) $cotizacion->total) }}</span>
                @if ($cotizacion->leida_con_ia)<span class="rounded bg-blue-100 px-1.5 py-0.5 text-xs text-blue-800">{{ __('Leída con IA') }}</span>@endif
                @if ($cotizacion->archivo_ruta)
                    <a href="{{ route('insumos.cotizaciones.archivo', $cotizacion) }}" target="_blank" rel="noopener" class="text-brand-teal hover:underline">{{ __('Ver PDF') }}</a>
                @endif
                <a href="{{ route('insumos.cotizaciones.edit', $cotizacion) }}" class="text-brand-teal hover:underline">{{ __('Editar') }}</a>
                <form method="POST" action="{{ route('insumos.cotizaciones.destroy', $cotizacion) }}" class="inline"
                      onsubmit="return confirm('{{ __('¿Eliminar esta cotización? Las partidas volverán a su precio estimado.') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-red-600 hover:underline">{{ __('Eliminar') }}</button>
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <table class="min-w-full text-sm">
                    <thead class="bg-brand-mist text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                        <tr>
                            <th class="px-3 py-3">{{ __('N°') }}</th>
                            <th class="px-3 py-3">{{ __('Cotizado') }}</th>
                            <th class="px-3 py-3">{{ __('Partida solicitada') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('Cant. cot. / sol.') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('P.U. cotizado') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('P.U. estimado') }}</th>
                            <th class="px-3 py-3">{{ __('Estatus') }}</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($cotizacion->renglones as $renglon)
                            @php
                                $partida = $renglon->partida;
                                [$texto, $clase] = $estilos[$renglon->estatus] ?? [$renglon->estatus, 'bg-gray-100 text-gray-700'];
                            @endphp
                            <tr class="align-top">
                                <td class="px-3 py-2">{{ $renglon->numero }}</td>
                                <td class="px-3 py-2">{{ $renglon->descripcion }}@if ($renglon->clave)<span class="block text-xs text-gray-500">{{ $renglon->clave }}</span>@endif</td>
                                <td class="px-3 py-2">{{ $partida ? $partida->solicitud->estacion->nombre.' · '.$partida->descripcion : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ (float) $renglon->cantidad }} / {{ $partida ? (float) $partida->cantidad : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $dinero($renglon->precio_unitario === null ? null : (float) $renglon->precio_unitario) }}</td>
                                <td class="px-3 py-2 text-right">{{ $partida ? $dinero($partida->precio_estimado !== null ? (float) $partida->precio_estimado : ($partida->precio_unitario === null ? null : (float) $partida->precio_unitario)) : '—' }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $clase }}">{{ __($texto) }}</span>
                                    @if ($renglon->motivo)<span class="mt-1 block text-xs text-gray-600">{{ $renglon->motivo }}@if ($renglon->motivo_detalle): {{ $renglon->motivo_detalle }}@endif</span>@endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    @if ($renglon->estatus === 'revisar' && $partida && $renglon->precio_unitario !== null)
                                        <form method="POST" action="{{ route('insumos.cotizaciones.aceptar', $renglon) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="min-h-[44px] px-2 text-brand-teal hover:underline">{{ __('Aceptar precio') }}</button>
                                        </form>
                                    @elseif ($renglon->estatus === 'actualizado')
                                        <form method="POST" action="{{ route('insumos.cotizaciones.rechazar', $renglon) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="min-h-[44px] px-2 text-red-600 hover:underline">{{ __('Rechazar') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div>
                <a href="{{ route('insumos.cotizaciones.index', ['anio' => $cotizacion->anio, 'mes' => $cotizacion->mes]) }}" class="text-sm text-gray-600 hover:text-brand-green">← {{ __('Volver') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
