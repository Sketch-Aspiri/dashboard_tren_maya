@php
    /** @var \App\Models\Estacion $estacion */
    /** @var int $anio */
    /** @var list<array{tipo: \App\Enums\TipoServicio, servicio: \App\Models\ServicioEstacion|null, pagos: \Illuminate\Support\Collection<int, \App\Models\PagoServicio>}> $bloques */
    /** @var bool $puedeEliminar */
    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $campoClasses = 'block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Gasto energético') }} — {{ $estacion->nombre }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-xl border border-brand-green/10 bg-brand-mist p-4 text-sm text-brand-green">
                    {{ session('status') }}
                </div>
            @endif

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="GET" action="{{ route('estadisticas.gasto-energetico.show', ['estacion' => $estacion->id]) }}" class="flex flex-wrap items-end gap-2">
                    <div class="max-w-[8rem]">
                        <x-input-label for="anio-gasto" :value="__('Año')" />
                        <x-text-input id="anio-gasto" name="anio" type="number" class="block mt-1 w-full" value="{{ $anio }}" min="2000" max="2100" />
                    </div>
                    <x-secondary-button type="submit">{{ __('Ver este año') }}</x-secondary-button>
                    <a href="{{ route('estadisticas.gasto-energetico.index', ['anio' => $anio]) }}" class="text-sm text-brand-green hover:underline">
                        {{ __('Volver al resumen') }}
                    </a>
                </form>
            </div>

            <form method="POST" action="{{ route('estadisticas.gasto-energetico.update', ['estacion' => $estacion->id]) }}" class="space-y-6">
                @csrf
                @method('PUT')
                <input type="hidden" name="anio" value="{{ $anio }}">

                @foreach ($bloques as $bloque)
                    @php
                        $tipo = $bloque['tipo'];
                        $servicio = $bloque['servicio'];
                        $prefijo = 'servicios.'.$tipo->value;
                        $nombre = 'servicios['.$tipo->value.']';
                    @endphp

                    <section class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6" aria-labelledby="titulo-{{ $tipo->value }}">
                        <h3 id="titulo-{{ $tipo->value }}" class="font-heading text-lg font-semibold text-brand-green">
                            {{ __('Pago de servicio de :servicio', ['servicio' => mb_strtolower($tipo->label())]) }}
                        </h3>

                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label :for="'proveedor-'.$tipo->value" :value="__('Proveedor')" />
                                <x-text-input :id="'proveedor-'.$tipo->value" type="text" maxlength="255" class="block mt-1 w-full"
                                              :name="$nombre.'[proveedor]'"
                                              :value="old($prefijo.'.proveedor', $servicio?->proveedor)" />
                                <x-input-error :messages="$errors->get($prefijo.'.proveedor')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label :for="'contrato-'.$tipo->value" :value="__('Contrato')" />
                                <x-text-input :id="'contrato-'.$tipo->value" type="text" maxlength="255" class="block mt-1 w-full"
                                              :name="$nombre.'[contrato]'"
                                              :value="old($prefijo.'.contrato', $servicio?->contrato)" />
                                <x-input-error :messages="$errors->get($prefijo.'.contrato')" class="mt-1" />
                            </div>
                        </div>

                        <div class="mt-4">
                            <x-input-label :for="'observaciones-'.$tipo->value" :value="__('Observaciones')" />
                            <textarea id="observaciones-{{ $tipo->value }}" name="{{ $nombre }}[observaciones]" rows="3" maxlength="5000"
                                      class="{{ $campoClasses }}">{{ old($prefijo.'.observaciones', $servicio?->observaciones) }}</textarea>
                            <x-input-error :messages="$errors->get($prefijo.'.observaciones')" class="mt-1" />
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-green/10 text-sm">
                                <thead class="bg-brand-mist/60">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 text-left font-heading font-semibold text-brand-green">{{ __('Mes') }}</th>
                                        <th scope="col" class="px-3 py-2 text-left font-heading font-semibold text-brand-green">{{ __('Monto pagado (MXN)') }}</th>
                                        <th scope="col" class="px-3 py-2 text-left font-heading font-semibold text-brand-green">{{ __('Acciones') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-green/10">
                                    @foreach ($meses as $numeroMes => $nombreMes)
                                        @php
                                            $pago = $bloque['pagos']->get($numeroMes);
                                            $monto = old($prefijo.'.meses.'.$numeroMes, $pago?->monto);
                                        @endphp
                                        <tr class="{{ $pago ? 'bg-brand-mist/30' : '' }}">
                                            <td class="px-3 py-2 font-medium text-gray-700">
                                                <div class="flex items-center gap-2">
                                                    <span>{{ $nombreMes }}</span>
                                                    @if ($pago)
                                                        <span class="inline-flex items-center rounded-full bg-brand-mist px-2 py-0.5 text-[11px] font-semibold text-brand-green" title="{{ __('Este mes ya tiene un pago capturado — edítalo abajo y presiona Guardar.') }}">
                                                            {{ __('Capturado') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="px-3 py-2">
                                                <x-text-input type="number" min="0" step="0.01" class="block w-40"
                                                              :name="$nombre.'[meses]['.$numeroMes.']'"
                                                              :value="$monto"
                                                              :aria-label="__('Monto de :mes', ['mes' => $nombreMes])" />
                                                <x-input-error :messages="$errors->get($prefijo.'.meses.'.$numeroMes)" class="mt-1" />
                                            </td>
                                            <td class="px-3 py-2">
                                                @if ($pago && $puedeEliminar)
                                                    {{-- The whole table lives inside the "Guardar" <form>, and HTML
                                                         doesn't allow nested <form> elements — a nested one would be
                                                         dropped by the browser and its @method('DELETE') would end
                                                         up turning "Guardar" into a DELETE. So the button points at a
                                                         real top-level <form> (rendered after this one closes) via
                                                         the HTML5 form="" attribute. --}}
                                                    <button type="submit" form="eliminar-pago-{{ $pago->id }}"
                                                            class="text-sm font-medium text-red-600 hover:text-red-800"
                                                            onclick="return confirm('{{ __('¿Eliminar este registro?') }}');">
                                                        {{ __('Eliminar') }}
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endforeach

                <x-input-error :messages="$errors->get('servicios')" />

                <div>
                    <x-primary-button type="submit">{{ __('Guardar año') }}</x-primary-button>
                </div>
            </form>

            {{-- Real top-level "Eliminar" forms, one per deletable payment, kept OUTSIDE
                 (siblings of) the "Guardar" form above; each row's button references its
                 form via form="". Reuses the already-loaded pagos, no extra queries. --}}
            @if ($puedeEliminar)
                @foreach ($bloques as $bloque)
                    @foreach ($bloque['pagos'] as $pago)
                        <form id="eliminar-pago-{{ $pago->id }}" method="POST"
                              action="{{ route('estadisticas.gasto-energetico.destroy', ['estacion' => $estacion->id, 'pago' => $pago->id]) }}"
                              class="hidden">
                            @csrf
                            @method('DELETE')
                        </form>
                    @endforeach
                @endforeach
            @endif

        </div>
    </div>
</x-app-layout>
