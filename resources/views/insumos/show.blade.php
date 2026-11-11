@php
    /** @var \App\Models\SolicitudInsumo $solicitud */
    /** @var array{subtotal: float, iva: float, total: float, desglose: list<array{proveedor: string|null, tasa: int, base: float, iva: float}>, limite: float, restante: float, excedeLimite: bool} $totales */
    /** @var float $limite */
    /** @var \Illuminate\Support\Collection<int, int> $ivaPorPartida */
    /** @var \Illuminate\Support\Collection<int, string> $proveedores */
    /** @var list<int> $atipicas */
    /** @var bool $puedeEditar */
    /** @var bool $puedeRevisar */
    /** @var \Illuminate\Support\Collection<int, \App\Models\CatalogoInsumo> $catalogo */
    /** @var array<string, list<array{mensaje: string}>> $avisosPorDescripcion */
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
        'precio' => $p->precio_unitario === null ? null : (float) $p->precio_unitario,
        'iva' => $ivaPorPartida[$p->id] ?? null,
    ])->values()->all());
    if (empty($filasIniciales)) {
        $filasIniciales = [['cantidad' => '', 'unidad' => 'Pieza', 'descripcion' => '', 'justificacion' => '', 'catalogo_insumo_id' => null, 'precio' => null, 'iva' => null]];
    }
    $conAvisos = collect($avisosPorDescripcion)->filter()->count();
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="$solicitud->estacion->nombre.' — '.$nombresMes[$solicitud->mes].' '.$solicitud->anio"
                          :detalle="__('Solicitud de insumos')"
                          :volver="$puedeEditar ? null : route('insumos.index', ['anio' => $solicitud->anio, 'mes' => $solicitud->mes])"
                          :volver-texto="__('Solicitudes del mes')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.nav activo="solicitudes" :anio="$solicitud->anio" :mes="$solicitud->mes" />

            <x-insumos.avisos />

            <x-seguimiento-tickets :modelo="$solicitud" />

            <x-insumos.pasos :estado="$solicitud->estado" />

            @if ($solicitud->estado === 'observada' && $solicitud->comentario_zona)
                <div class="ins-aviso-alerta" role="status">
                    <span aria-hidden="true" class="mt-0.5 font-bold">!</span>
                    <div>
                        <p class="font-semibold">{{ __('La Zona devolvió la solicitud con este comentario:') }}</p>
                        <p class="mt-1 whitespace-pre-line">{{ $solicitud->comentario_zona }}</p>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-600">
                <x-insumos.estado :valor="$solicitud->estado" :texto="__($solicitud->etiquetaEstado())" />
                @if ($solicitud->oficio_numero)
                    <span>{{ __('Oficio') }}: <span class="font-medium text-gray-900">{{ $solicitud->oficio_numero }}</span></span>
                @endif
                @if ($solicitud->enviada_at)
                    <span>{{ __('Enviada') }}: <span class="font-medium text-gray-900">{{ $solicitud->enviada_at->format('d/m/Y H:i') }}</span></span>
                @endif
                @if ($solicitud->revisor)
                    <span>{{ __('Revisó') }}: <span class="font-medium text-gray-900">{{ $solicitud->revisor->name }}</span></span>
                @endif
                @if ($solicitud->partidas->isNotEmpty())
                    <a href="{{ route('insumos.formato', $solicitud) }}" class="ins-btn-secundario !min-h-[36px] !px-3 sm:ml-auto">{{ __('Descargar formato (Excel)') }}</a>
                @endif
            </div>

            @if ($puedeEditar)
                {{-- Captura de la estación: cantidad, unidad, descripción y justificación. --}}
                <form id="form-solicitud" method="POST" action="{{ route('insumos.update', $solicitud) }}"
                      x-data="solicitudInsumos"
                      data-limite="{{ $limite }}" data-iva-defecto="{{ (int) config('insumos.iva_defecto') }}"
                      data-estacion="{{ $solicitud->estacion_id }}" data-url-verificar="{{ route('insumos.inventario.verificar') }}"
                      data-sucio="{{ old('partidas') !== null || $solicitud->partidas->isEmpty() ? '1' : '0' }}"
                      data-filas='@json($filasIniciales)' data-catalogo='@json($catalogo)' data-avisos='@json($avisosPorDescripcion)'
                      @input="sucio = true"
                      class="space-y-6">
                    @csrf
                    @method('PUT')

                    {{-- Presupuesto de la estación: límite mensual con IVA y lo que va quedando. --}}
                    <div class="ins-card sticky top-2 z-10 p-4 sm:px-6" :class="restante() < 0 ? '!border-red-300' : ''" aria-live="polite">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                            <p class="font-heading text-lg font-semibold" :class="restante() < 0 ? 'text-red-700' : 'text-brand-green'"
                               x-text="restante() < 0 ? '{{ __('Te pasaste por') }} ' + dinero(-restante()) : '{{ __('Te quedan') }} ' + dinero(restante())"></p>
                            <p class="text-sm text-gray-600">
                                {{ __('Estimado') }} <span class="font-semibold tabular-nums text-gray-900" x-text="dinero(estimado())"></span>
                                {{ __('de') }} <span class="font-semibold tabular-nums text-gray-900" x-text="dinero(limite)"></span>
                                <span class="text-gray-500">({{ __('límite con IVA') }})</span>
                            </p>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                            <div class="h-2 rounded-full transition-all" :class="restante() < 0 ? 'bg-red-500' : (estimado() > limite * 0.85 ? 'bg-amber-500' : 'bg-brand-teal')"
                                 :style="`width: ${Math.min(100, estimado() / limite * 100)}%`"></div>
                        </div>
                        <p x-show="sinPrecio() > 0" x-cloak class="mt-2 text-xs text-amber-700">
                            <span x-text="sinPrecio()"></span> {{ __('partida(s) sin precio estimado todavía no cuentan: la Zona les pondrá precio al revisar.') }}
                        </p>
                        <p x-show="restante() < 0" x-cloak class="mt-2 text-xs font-medium text-red-700">{{ __('No podrás enviar la solicitud mientras el total estimado exceda el límite.') }}</p>
                    </div>

                    <section class="ins-card ins-card-pad" aria-labelledby="datos-titulo">
                        <h3 id="datos-titulo" class="ins-titulo">{{ __('Datos del servicio') }}</h3>
                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <x-input-label for="tipo_servicio" :value="__('Tipo de servicio')" />
                                <select id="tipo_servicio" name="tipo_servicio" class="ins-campo mt-1">
                                    @foreach (['1er escalón', '2do escalón'] as $tipo)
                                        <option value="{{ $tipo }}" @selected(old('tipo_servicio', $solicitud->tipo_servicio) === $tipo)>{{ $tipo }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="lugar_trabajo" :value="__('Lugar del trabajo')" />
                                <input id="lugar_trabajo" name="lugar_trabajo" type="text" class="ins-campo mt-1" value="{{ old('lugar_trabajo', $solicitud->lugar_trabajo) }}">
                            </div>
                            <div>
                                <x-input-label for="solicitante_nombre" :value="__('Nombre del solicitante')" />
                                <input id="solicitante_nombre" name="solicitante_nombre" type="text" autocomplete="name" class="ins-campo mt-1" value="{{ old('solicitante_nombre', $solicitud->solicitante_nombre) }}">
                            </div>
                            <div>
                                <x-input-label for="solicitante_puesto" :value="__('Puesto del solicitante')" />
                                <input id="solicitante_puesto" name="solicitante_puesto" type="text" autocomplete="organization-title" class="ins-campo mt-1" value="{{ old('solicitante_puesto', $solicitud->solicitante_puesto) }}">
                            </div>
                        </div>
                        <div class="mt-4">
                            <x-input-label for="justificacion_general" :value="__('Justificación general')" />
                            <textarea id="justificacion_general" name="justificacion_general" rows="2" class="ins-campo mt-1">{{ old('justificacion_general', $solicitud->justificacion_general) }}</textarea>
                        </div>
                    </section>

                    <section aria-labelledby="partidas-titulo" class="space-y-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 id="partidas-titulo" class="ins-titulo">{{ __('Insumos que solicitas') }} <span class="font-normal text-gray-500" x-text="'(' + filas.length + ')'"></span></h3>
                            <p class="text-xs text-gray-500">{{ __('Empieza a escribir la descripción para elegirla del catálogo. La justificación es obligatoria.') }}</p>
                        </div>

                        <datalist id="catalogo-insumos">
                            <template x-for="c in catalogo" :key="c.id"><option :value="c.descripcion"></option></template>
                        </datalist>

                        <template x-for="(fila, i) in filas" :key="i">
                            <div class="ins-card p-4" :class="avisosDe(fila).length ? '!border-amber-300' : ''">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Partida') }} <span x-text="i + 1"></span></p>
                                    <div class="flex items-center gap-3">
                                        <p class="text-sm tabular-nums text-gray-600" x-show="fila.descripcion">
                                            <span x-show="importe(fila) !== null">≈ <span class="font-semibold text-gray-900" x-text="importe(fila) === null ? '' : dinero(importe(fila))"></span> <span class="text-xs text-gray-500">{{ __('con IVA') }}</span></span>
                                            <span x-show="importe(fila) === null" class="text-xs text-amber-700">{{ __('Sin precio estimado') }}</span>
                                        </p>
                                        <button type="button" @click="quitar(i)" x-show="filas.length > 1" class="ins-enlace-peligro !min-h-[36px]" :aria-label="'{{ __('Quitar partida') }} ' + (i + 1)">{{ __('Quitar') }}</button>
                                    </div>
                                </div>

                                <div class="mt-2 grid grid-cols-2 gap-3 lg:grid-cols-12">
                                    <div class="col-span-2 lg:col-span-6">
                                        <label :for="`p-${i}-descripcion`" class="ins-etiqueta">{{ __('Descripción') }}</label>
                                        <input :id="`p-${i}-descripcion`" type="text" required maxlength="255" list="catalogo-insumos" class="ins-campo mt-1"
                                               :name="`partidas[${i}][descripcion]`" x-model="fila.descripcion" @change="elegir(i); revisar(i)">
                                        <input type="hidden" :name="`partidas[${i}][catalogo_insumo_id]`" :value="fila.catalogo_insumo_id ?? ''">
                                    </div>
                                    <div class="lg:col-span-2">
                                        <label :for="`p-${i}-cantidad`" class="ins-etiqueta">{{ __('Cant.') }}</label>
                                        <input :id="`p-${i}-cantidad`" type="number" inputmode="decimal" step="0.01" min="0.01" required class="ins-campo mt-1"
                                               :name="`partidas[${i}][cantidad]`" x-model="fila.cantidad">
                                    </div>
                                    <div class="lg:col-span-4">
                                        <label :for="`p-${i}-unidad`" class="ins-etiqueta">{{ __('Unidad') }}</label>
                                        <input :id="`p-${i}-unidad`" type="text" required maxlength="30" class="ins-campo mt-1"
                                               :name="`partidas[${i}][unidad]`" x-model="fila.unidad">
                                    </div>
                                    <div class="col-span-2 lg:col-span-12">
                                        <label :for="`p-${i}-justificacion`" class="ins-etiqueta">{{ __('Justificación') }} *</label>
                                        <input :id="`p-${i}-justificacion`" type="text" maxlength="2000" required class="ins-campo mt-1"
                                               :class="avisosDe(fila).length && ! (fila.justificacion || '').trim() ? '!border-amber-400' : ''"
                                               placeholder="{{ __('¿Para qué se necesita?') }}"
                                               :name="`partidas[${i}][justificacion]`" x-model="fila.justificacion">
                                    </div>
                                </div>

                                <template x-for="aviso in avisosDe(fila)" :key="aviso.mensaje">
                                    <p class="mt-3 flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">
                                        <span aria-hidden="true" class="font-bold">!</span>
                                        <span x-text="aviso.mensaje + ' Explica en la justificación por qué lo necesitas.'"></span>
                                    </p>
                                </template>
                            </div>
                        </template>

                        <button type="button" @click="agregar()" class="ins-btn-secundario w-full border-dashed sm:w-auto">+ {{ __('Agregar partida') }}</button>
                    </section>

                    <div class="ins-barra-acciones">
                        <p class="text-sm" :class="sucio ? 'text-amber-700' : 'text-gray-500'" role="status"
                           x-text="sucio ? '{{ __('Tienes cambios sin guardar. Guarda antes de enviar.') }}' : '{{ __('Todo guardado. Cuando termines, envía la solicitud a la Zona.') }}'"></p>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row">
                            <button type="submit" class="ins-btn-secundario">{{ __('Guardar borrador') }}</button>
                            @if ($solicitud->partidas->isNotEmpty())
                                <button type="submit" form="form-enviar" class="ins-btn-primario" :disabled="sucio || restante() < 0"
                                        :title="sucio ? '{{ __('Guarda primero los cambios') }}' : ''">{{ __('Enviar a la Zona') }}</button>
                            @endif
                        </div>
                    </div>
                </form>

                @if ($solicitud->partidas->isNotEmpty())
                    <form id="form-enviar" method="POST" action="{{ route('insumos.enviar', $solicitud) }}" class="hidden">
                        @csrf
                    </form>
                @endif

                @push('scripts')
                    <script>
                        document.addEventListener('alpine:init', () => {
                            Alpine.data('solicitudInsumos', () => ({
                                limite: 0, ivaDefecto: 0, estacionId: null, urlVerificar: '', sucio: false,
                                filas: [], catalogo: [], avisos: {},

                                init() {
                                    const d = this.$el.dataset;
                                    this.limite = Number(d.limite);
                                    this.ivaDefecto = Number(d.ivaDefecto);
                                    this.estacionId = d.estacion;
                                    this.urlVerificar = d.urlVerificar;
                                    this.sucio = d.sucio === '1';
                                    this.filas = JSON.parse(d.filas);
                                    this.catalogo = JSON.parse(d.catalogo);
                                    const avisos = JSON.parse(d.avisos);
                                    this.avisos = Array.isArray(avisos) ? {} : avisos;
                                },
                                agregar() {
                                    this.filas.push({ cantidad: '', unidad: 'Pieza', descripcion: '', justificacion: '', catalogo_insumo_id: null, precio: null, iva: null });
                                    this.sucio = true;
                                    this.$nextTick(() => document.getElementById(`p-${this.filas.length - 1}-descripcion`)?.focus());
                                },
                                quitar(i) {
                                    if (this.filas.length > 1) { this.filas.splice(i, 1); this.sucio = true; }
                                },
                                elegir(i) {
                                    const hallado = this.catalogo.find(c => c.descripcion === this.filas[i].descripcion);
                                    this.filas[i].catalogo_insumo_id = hallado ? hallado.id : null;
                                    this.filas[i].precio = null;
                                    this.filas[i].iva = null;
                                    if (hallado) this.filas[i].unidad = hallado.unidad;
                                },
                                async revisar(i) {
                                    const d = (this.filas[i].descripcion || '').trim();
                                    if (d.length < 3) return;
                                    try {
                                        const r = await fetch(this.urlVerificar + '?estacion=' + this.estacionId + '&descripcion=' + encodeURIComponent(d), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                                        if (r.ok) this.avisos[d] = (await r.json()).data;
                                    } catch (e) { /* sin conexión: el aviso se calcula de nuevo al guardar */ }
                                },
                                avisosDe(f) { return this.avisos[(f.descripcion || '').trim()] || []; },
                                enCatalogo(f) { return this.catalogo.find(c => c.id == f.catalogo_insumo_id); },
                                precioDe(f) {
                                    if (f.precio !== null && f.precio !== undefined) return Number(f.precio);
                                    const c = this.enCatalogo(f);
                                    return c && c.precio !== null ? c.precio : null;
                                },
                                ivaDe(f) {
                                    if (f.iva !== null && f.iva !== undefined) return Number(f.iva);
                                    const c = this.enCatalogo(f);
                                    return c ? c.iva : this.ivaDefecto;
                                },
                                importe(f) {
                                    const p = this.precioDe(f);
                                    return p === null ? null : (parseFloat(f.cantidad) || 0) * p * (1 + this.ivaDe(f) / 100);
                                },
                                estimado() { return this.filas.reduce((t, f) => t + (this.importe(f) ?? 0), 0); },
                                sinPrecio() { return this.filas.filter(f => f.descripcion && this.precioDe(f) === null).length; },
                                restante() { return this.limite - this.estimado(); },
                                dinero(n) { return n.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }); },
                            }));
                        });
                    </script>
                @endpush
            @else
                {{-- Vista de solo lectura o revisión de la Zona. --}}
                <form method="POST" action="{{ route('insumos.revisar', $solicitud) }}" class="space-y-6">
                    @csrf

                    <datalist id="lista-proveedores">
                        @foreach ($proveedores as $nombreProveedor)
                            <option value="{{ $nombreProveedor }}"></option>
                        @endforeach
                    </datalist>

                    <section class="ins-card ins-card-pad">
                        <dl class="grid grid-cols-2 gap-4 text-sm lg:grid-cols-4">
                            <div><dt class="ins-etiqueta">{{ __('Tipo de servicio') }}</dt><dd class="mt-0.5 text-gray-900">{{ $solicitud->tipo_servicio ?: '—' }}</dd></div>
                            <div><dt class="ins-etiqueta">{{ __('Lugar del trabajo') }}</dt><dd class="mt-0.5 text-gray-900">{{ $solicitud->lugar_trabajo ?: '—' }}</dd></div>
                            <div><dt class="ins-etiqueta">{{ __('Solicitante') }}</dt><dd class="mt-0.5 text-gray-900">{{ $solicitud->solicitante_nombre ?: '—' }}</dd></div>
                            <div><dt class="ins-etiqueta">{{ __('Puesto') }}</dt><dd class="mt-0.5 text-gray-900">{{ $solicitud->solicitante_puesto ?: '—' }}</dd></div>
                            @if ($solicitud->justificacion_general)
                                <div class="col-span-2 lg:col-span-4"><dt class="ins-etiqueta">{{ __('Justificación general') }}</dt><dd class="mt-0.5 whitespace-pre-line text-gray-900">{{ $solicitud->justificacion_general }}</dd></div>
                            @endif
                        </dl>
                    </section>

                    @if ($conAvisos > 0)
                        <div class="ins-aviso-alerta" role="status">
                            <span aria-hidden="true" class="mt-0.5 font-bold">!</span>
                            <p>{{ trans_choice(':n partida pide algo que la estación ya tiene o recibió hace poco, según sus resguardos. Revisa su justificación.|:n partidas piden algo que la estación ya tiene o recibió hace poco, según sus resguardos. Revisa su justificación.', $conAvisos, ['n' => $conAvisos]) }}
                                <a href="{{ route('insumos.inventario.index', ['estacion' => $solicitud->estacion_id]) }}" class="font-semibold underline">{{ __('Ver inventario de la estación') }}</a>
                            </p>
                        </div>
                    @endif

                    <div class="ins-tabla-marco">
                        <table class="ins-tabla">
                            <caption class="sr-only">{{ __('Partidas de la solicitud') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('N°') }}</th>
                                    <th scope="col" class="!text-right">{{ __('Cant.') }}</th>
                                    <th scope="col">{{ __('Unidad') }}</th>
                                    <th scope="col">{{ __('Descripción') }}</th>
                                    <th scope="col">{{ __('Justificación') }}</th>
                                    <th scope="col">{{ __('Código') }}</th>
                                    <th scope="col">{{ __('P. unitario') }}</th>
                                    <th scope="col">{{ __('Partida') }}</th>
                                    <th scope="col">{{ __('Proveedor') }}</th>
                                    <th scope="col" class="!text-right">{{ __('Total') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($solicitud->partidas as $partida)
                                    @php $n = $partida->numero; @endphp
                                    <tr class="{{ in_array($partida->id, $atipicas, true) ? '!bg-amber-50' : '' }} {{ $partida->eliminada_por_coordinacion ? 'opacity-60' : '' }}">
                                        <td>{{ $n }}</td>
                                        <td class="num">{{ $partida->cantidad + 0 }}</td>
                                        <td>{{ $partida->unidad }}</td>
                                        <td class="min-w-[14rem]">
                                            <span class="fuerte {{ $partida->eliminada_por_coordinacion ? 'line-through' : '' }}">{{ $partida->descripcion }}</span>
                                            @if ($partida->eliminada_por_coordinacion)
                                                <span class="ins-chip-error ml-1">{{ __('quitada por la Coordinación') }}</span>
                                            @elseif ($partida->agregada_por_coordinacion)
                                                <span class="ins-chip-info ml-1">{{ __('agregada por la Coordinación') }}</span>
                                            @endif
                                            @foreach ($avisosPorDescripcion[trim($partida->descripcion)] ?? [] as $aviso)
                                                <p class="mt-1.5 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-900"><span class="font-semibold">{{ __('Inventario') }}:</span> {{ $aviso['mensaje'] }}</p>
                                            @endforeach
                                        </td>
                                        <td class="min-w-[12rem] text-gray-600">{{ $partida->justificacion ?: '—' }}</td>
                                        @if ($puedeRevisar)
                                            <td><input type="text" maxlength="50" class="ins-campo w-28" aria-label="{{ __('Código de la partida :n', ['n' => $n]) }}" name="partidas[{{ $partida->id }}][codigo]" value="{{ old('partidas.'.$partida->id.'.codigo', $partida->codigo) }}"></td>
                                            <td><input type="number" inputmode="decimal" step="0.01" min="0" class="ins-campo w-28 text-right" aria-label="{{ __('Precio unitario de la partida :n', ['n' => $n]) }}" name="partidas[{{ $partida->id }}][precio_unitario]" value="{{ old('partidas.'.$partida->id.'.precio_unitario', $partida->precio_unitario) }}"></td>
                                            <td><input type="text" inputmode="numeric" maxlength="10" class="ins-campo w-24" aria-label="{{ __('Partida presupuestal de la partida :n', ['n' => $n]) }}" name="partidas[{{ $partida->id }}][partida_presupuestal]" value="{{ old('partidas.'.$partida->id.'.partida_presupuestal', $partida->partida_presupuestal ?: $partida->catalogo?->partida_presupuestal) }}"></td>
                                            <td><input type="text" maxlength="255" class="ins-campo w-44" list="lista-proveedores" aria-label="{{ __('Proveedor de la partida :n', ['n' => $n]) }}" name="partidas[{{ $partida->id }}][proveedor]" value="{{ old('partidas.'.$partida->id.'.proveedor', $partida->proveedor) }}"></td>
                                        @else
                                            <td>{{ $partida->codigo ?? '—' }}</td>
                                            <td class="num">{{ $dinero($partida->precio_unitario === null ? null : (float) $partida->precio_unitario) }}</td>
                                            <td>{{ $partida->partida_final ?? $partida->partida_presupuestal ?? '—' }}</td>
                                            <td>{{ $partida->proveedor ?? '—' }}</td>
                                        @endif
                                        <td class="num fuerte">{{ $dinero($partida->total()) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if (! empty($atipicas))
                        <p class="text-sm text-amber-800">{{ __('Las filas resaltadas tienen un precio que se desvía más de 30 % de la mediana de los últimos 3 meses.') }}</p>
                    @endif

                    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <x-kpi-card :label="__('Subtotal')" :value="$dinero($totales['subtotal'])" />
                        <x-kpi-card :label="__('IVA (por proveedor)')" :value="$dinero($totales['iva'])"
                                    :hint="collect($totales['desglose'])->map(fn ($g) => ($g['proveedor'] ?? __('Sin proveedor')).' '.$g['tasa'].' %: '.$dinero($g['iva']))->implode(' · ')" />
                        <x-kpi-card :label="__('Total')" :value="$dinero($totales['total'])" />
                        <x-kpi-card :label="$totales['excedeLimite'] ? __('Excede el límite') : __('Presupuesto restante')" :value="$dinero($totales['restante'])"
                                    :tono="$totales['excedeLimite'] ? 'peligro' : null"
                                    :hint="__('Límite de la estación: :limite con IVA', ['limite' => $dinero($totales['limite'])])" />
                    </div>

                    @if ($puedeRevisar)
                        <section class="ins-card ins-card-pad">
                            <x-input-label for="comentario_zona" :value="__('Comentario para la estación (obligatorio al devolver)')" />
                            <textarea id="comentario_zona" name="comentario_zona" rows="3" class="ins-campo mt-1" placeholder="{{ __('Qué debe corregir la estación…') }}">{{ old('comentario_zona') }}</textarea>
                        </section>

                        <div class="ins-barra-acciones">
                            <p class="text-sm text-gray-500">{{ __('Guardar precios no cambia el estado; aprobar la pasa al consolidado.') }}</p>
                            <div class="flex flex-col-reverse gap-2 sm:flex-row">
                                <button type="submit" name="accion" value="devolver" class="ins-btn-peligro">{{ __('Devolver a la estación') }}</button>
                                <button type="submit" name="accion" value="guardar" class="ins-btn-secundario">{{ __('Guardar precios') }}</button>
                                <button type="submit" name="accion" value="aprobar" class="ins-btn-primario">{{ __('Aprobar') }}</button>
                            </div>
                        </div>
                    @endif
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
