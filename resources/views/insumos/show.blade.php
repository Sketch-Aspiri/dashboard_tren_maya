@php
    /** @var \App\Models\SolicitudInsumo $solicitud */
    /** @var array{subtotal: float, iva: float, total: float, excedeTope: bool} $totales */
    /** @var list<int> $atipicas */
    /** @var bool $puedeEditar */
    /** @var bool $puedeRevisar */
    /** @var \Illuminate\Support\Collection<int, \App\Models\CatalogoInsumo> $catalogo */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $dinero = fn (?float $monto) => $monto === null ? '—' : '$'.number_format($monto, 2);
    $filasIniciales = old('partidas', $solicitud->partidas->map(fn ($p) => [
        'cantidad' => $p->cantidad + 0,
        'unidad' => $p->unidad,
        'descripcion' => $p->descripcion,
        'justificacion' => $p->justificacion,
        'catalogo_insumo_id' => $p->catalogo_insumo_id,
    ])->values()->all());
    if (empty($filasIniciales)) {
        $filasIniciales = [['cantidad' => '', 'unidad' => 'Pieza', 'descripcion' => '', 'justificacion' => '', 'catalogo_insumo_id' => null]];
    }
    $campo = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal text-sm';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Solicitud de insumos') }} — {{ $solicitud->estacion->nombre }} — {{ $nombresMes[$solicitud->mes] }} {{ $solicitud->anio }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-xl border border-brand-green/10 bg-brand-mist p-4 text-sm text-brand-green">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($solicitud->estado === 'observada' && $solicitud->comentario_zona)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    <p class="font-semibold">{{ __('La Zona devolvió la solicitud con este comentario:') }}</p>
                    <p class="mt-1 whitespace-pre-line">{{ $solicitud->comentario_zona }}</p>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                <span class="inline-flex rounded-full bg-brand-mist px-3 py-1 font-medium text-brand-green">{{ __(ucfirst($solicitud->estado)) }}</span>
                @if ($solicitud->enviada_at)
                    <span>{{ __('Enviada') }}: {{ $solicitud->enviada_at->format('d/m/Y H:i') }}</span>
                @endif
                @if ($solicitud->revisor)
                    <span>{{ __('Revisó') }}: {{ $solicitud->revisor->name }}</span>
                @endif
            </div>

            @if ($puedeEditar)
                {{-- Captura de la estación: cantidad, unidad, descripción y justificación. --}}
                <form method="POST" action="{{ route('insumos.update', $solicitud) }}"
                      x-data='{ filas: @json($filasIniciales), catalogo: @json($catalogo), agregar() { this.filas.push({cantidad: "", unidad: "Pieza", descripcion: "", justificacion: "", catalogo_insumo_id: null}) }, quitar(i) { if (this.filas.length > 1) this.filas.splice(i, 1) }, elegir(i) { const hallado = this.catalogo.find(c => c.descripcion === this.filas[i].descripcion); this.filas[i].catalogo_insumo_id = hallado ? hallado.id : null; if (hallado) this.filas[i].unidad = hallado.unidad } }'
                      class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="tipo_servicio" :value="__('Tipo de servicio')" />
                                <select id="tipo_servicio" name="tipo_servicio" class="{{ $campo }}">
                                    @foreach (['1er escalón', '2do escalón'] as $tipo)
                                        <option value="{{ $tipo }}" @selected(old('tipo_servicio', $solicitud->tipo_servicio) === $tipo)>{{ $tipo }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="lugar_trabajo" :value="__('Lugar del trabajo')" />
                                <x-text-input id="lugar_trabajo" name="lugar_trabajo" type="text" class="block mt-1 w-full"
                                              :value="old('lugar_trabajo', $solicitud->lugar_trabajo)" />
                            </div>
                            <div>
                                <x-input-label for="solicitante_nombre" :value="__('Nombre del solicitante')" />
                                <x-text-input id="solicitante_nombre" name="solicitante_nombre" type="text" class="block mt-1 w-full"
                                              :value="old('solicitante_nombre', $solicitud->solicitante_nombre)" />
                            </div>
                            <div>
                                <x-input-label for="solicitante_puesto" :value="__('Puesto del solicitante')" />
                                <x-text-input id="solicitante_puesto" name="solicitante_puesto" type="text" class="block mt-1 w-full"
                                              :value="old('solicitante_puesto', $solicitud->solicitante_puesto)" />
                            </div>
                        </div>
                        <div class="mt-4">
                            <x-input-label for="justificacion_general" :value="__('Justificación general')" />
                            <textarea id="justificacion_general" name="justificacion_general" rows="3" class="{{ $campo }}">{{ old('justificacion_general', $solicitud->justificacion_general) }}</textarea>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                        <datalist id="catalogo-insumos">
                            <template x-for="c in catalogo" :key="c.id"><option :value="c.descripcion"></option></template>
                        </datalist>
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                                <tr>
                                    <th class="px-2 py-2">{{ __('N°') }}</th>
                                    <th class="px-2 py-2">{{ __('Cant.') }}</th>
                                    <th class="px-2 py-2">{{ __('Unidad') }}</th>
                                    <th class="px-2 py-2">{{ __('Descripción') }}</th>
                                    <th class="px-2 py-2">{{ __('Justificación') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(fila, i) in filas" :key="i">
                                    <tr class="align-top">
                                        <td class="px-2 py-1" x-text="i + 1"></td>
                                        <td class="px-2 py-1"><input type="number" step="0.01" min="0.01" required class="{{ $campo }} w-24" :name="`partidas[${i}][cantidad]`" x-model="fila.cantidad"></td>
                                        <td class="px-2 py-1"><input type="text" required maxlength="30" class="{{ $campo }} w-28" :name="`partidas[${i}][unidad]`" x-model="fila.unidad"></td>
                                        <td class="px-2 py-1">
                                            <input type="text" required maxlength="255" list="catalogo-insumos" class="{{ $campo }} min-w-[16rem]" :name="`partidas[${i}][descripcion]`" x-model="fila.descripcion" @change="elegir(i)">
                                            <input type="hidden" :name="`partidas[${i}][catalogo_insumo_id]`" :value="fila.catalogo_insumo_id ?? ''">
                                        </td>
                                        <td class="px-2 py-1"><input type="text" maxlength="2000" class="{{ $campo }} min-w-[14rem]" :name="`partidas[${i}][justificacion]`" x-model="fila.justificacion"></td>
                                        <td class="px-2 py-1"><button type="button" @click="quitar(i)" class="min-h-[44px] px-2 text-red-600 hover:underline">{{ __('Quitar') }}</button></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <button type="button" @click="agregar()" class="mt-3 min-h-[44px] text-sm text-brand-teal hover:underline">+ {{ __('Agregar partida') }}</button>
                    </div>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <x-primary-button type="submit">{{ __('Guardar borrador') }}</x-primary-button>
                    </div>
                </form>

                @if ($solicitud->partidas->isNotEmpty())
                    <form method="POST" action="{{ route('insumos.enviar', $solicitud) }}" class="flex justify-end">
                        @csrf
                        <x-secondary-button type="submit">{{ __('Enviar a la Zona') }}</x-secondary-button>
                    </form>
                @endif
            @else
                {{-- Vista de solo lectura o revisión de la Zona. --}}
                <form method="POST" action="{{ route('insumos.revisar', $solicitud) }}" class="space-y-6">
                    @csrf

                    <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                        <p class="mb-3 text-sm text-gray-600">
                            {{ $solicitud->tipo_servicio }} · {{ $solicitud->lugar_trabajo }}
                            @if ($solicitud->solicitante_nombre) · {{ $solicitud->solicitante_nombre }} ({{ $solicitud->solicitante_puesto }}) @endif
                        </p>
                        @if ($solicitud->justificacion_general)
                            <p class="mb-3 text-sm text-gray-700">{{ $solicitud->justificacion_general }}</p>
                        @endif
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                                <tr>
                                    <th class="px-2 py-2">{{ __('N°') }}</th>
                                    <th class="px-2 py-2">{{ __('Cant.') }}</th>
                                    <th class="px-2 py-2">{{ __('Unidad') }}</th>
                                    <th class="px-2 py-2">{{ __('Descripción') }}</th>
                                    <th class="px-2 py-2">{{ __('Justificación') }}</th>
                                    <th class="px-2 py-2">{{ __('Código') }}</th>
                                    <th class="px-2 py-2">{{ __('P. unitario') }}</th>
                                    <th class="px-2 py-2">{{ __('Partida') }}</th>
                                    <th class="px-2 py-2">{{ __('Proveedor') }}</th>
                                    <th class="px-2 py-2 text-right">{{ __('Total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($solicitud->partidas as $partida)
                                    <tr class="align-top {{ in_array($partida->id, $atipicas, true) ? 'bg-amber-50' : '' }}">
                                        <td class="px-2 py-2">{{ $partida->numero }}</td>
                                        <td class="px-2 py-2">{{ $partida->cantidad + 0 }}</td>
                                        <td class="px-2 py-2">{{ $partida->unidad }}</td>
                                        <td class="px-2 py-2">{{ $partida->descripcion }}</td>
                                        <td class="px-2 py-2 text-gray-600">{{ $partida->justificacion }}</td>
                                        @if ($puedeRevisar)
                                            <td class="px-2 py-1"><input type="text" maxlength="50" class="{{ $campo }} w-28" name="partidas[{{ $partida->id }}][codigo]" value="{{ old('partidas.'.$partida->id.'.codigo', $partida->codigo) }}"></td>
                                            <td class="px-2 py-1"><input type="number" step="0.01" min="0" class="{{ $campo }} w-28" name="partidas[{{ $partida->id }}][precio_unitario]" value="{{ old('partidas.'.$partida->id.'.precio_unitario', $partida->precio_unitario) }}"></td>
                                            <td class="px-2 py-1"><input type="text" maxlength="10" class="{{ $campo }} w-24" name="partidas[{{ $partida->id }}][partida_presupuestal]" value="{{ old('partidas.'.$partida->id.'.partida_presupuestal', $partida->partida_presupuestal) }}"></td>
                                            <td class="px-2 py-1"><input type="text" maxlength="255" class="{{ $campo }} w-40" name="partidas[{{ $partida->id }}][proveedor]" value="{{ old('partidas.'.$partida->id.'.proveedor', $partida->proveedor) }}"></td>
                                        @else
                                            <td class="px-2 py-2">{{ $partida->codigo ?? '—' }}</td>
                                            <td class="px-2 py-2">{{ $dinero($partida->precio_unitario === null ? null : (float) $partida->precio_unitario) }}</td>
                                            <td class="px-2 py-2">{{ $partida->partida_presupuestal ?? '—' }}</td>
                                            <td class="px-2 py-2">{{ $partida->proveedor ?? '—' }}</td>
                                        @endif
                                        <td class="px-2 py-2 text-right">{{ $dinero($partida->total()) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if (! empty($atipicas))
                            <p class="mt-3 text-sm text-amber-700">{{ __('Las filas resaltadas tienen un precio que se desvía más de 30 % de la mediana de los últimos 3 meses.') }}</p>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <x-kpi-card :label="__('Subtotal')" :value="$dinero($totales['subtotal'])" />
                        <x-kpi-card :label="__('IVA 8 %')" :value="$dinero($totales['iva'])" />
                        <x-kpi-card :label="__('Total')" :value="$dinero($totales['total'])" />
                        <x-kpi-card :label="__('Fondo rotatorio')" :value="$totales['excedeTope'] ? __('Excede $50,000') : __('Dentro de $50,000')" />
                    </div>

                    @if ($puedeRevisar)
                        <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                            <x-input-label for="comentario_zona" :value="__('Comentario para la estación (obligatorio al devolver)')" />
                            <textarea id="comentario_zona" name="comentario_zona" rows="3" class="{{ $campo }}">{{ old('comentario_zona') }}</textarea>
                            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:justify-end">
                                <x-secondary-button type="submit" name="accion" value="guardar">{{ __('Guardar precios') }}</x-secondary-button>
                                <x-secondary-button type="submit" name="accion" value="devolver">{{ __('Devolver a la estación') }}</x-secondary-button>
                                <x-primary-button type="submit" name="accion" value="aprobar">{{ __('Aprobar') }}</x-primary-button>
                            </div>
                        </div>
                    @endif
                </form>
            @endif

            <div>
                <a href="{{ route('insumos.index', ['anio' => $solicitud->anio, 'mes' => $solicitud->mes]) }}" class="text-sm text-gray-600 hover:text-brand-green">← {{ __('Volver') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
