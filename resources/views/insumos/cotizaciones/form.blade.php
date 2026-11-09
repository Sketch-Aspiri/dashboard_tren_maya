@php
    /** @var \App\Models\Cotizacion|null $cotizacion */
    /** @var int $anio */
    /** @var int $mes */
    /** @var array<int, string> $meses */
    /** @var \Illuminate\Support\Collection<int, array{id: int, etiqueta: string, cantidad: float, precio: float|null}> $partidas */
    /** @var \Illuminate\Support\Collection<int, string> $proveedores */
    /** @var bool $iaDisponible */
    $campo = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal text-sm';
    $editando = $cotizacion !== null;
    $renglonesIniciales = old('renglones', $editando ? $cotizacion->renglones->map(fn ($r) => [
        'partida_id' => $r->solicitud_insumo_partida_id,
        'clave' => $r->clave,
        'descripcion' => $r->descripcion,
        'cantidad' => (float) $r->cantidad,
        'precio_unitario' => $r->precio_unitario === null ? null : (float) $r->precio_unitario,
        'importe' => $r->importe === null ? null : (float) $r->importe,
        'coincidencia' => $r->coincidencia,
    ])->values()->all() : []);
    if (empty($renglonesIniciales)) {
        $renglonesIniciales = [['partida_id' => null, 'clave' => null, 'descripcion' => '', 'cantidad' => '', 'precio_unitario' => null, 'importe' => null, 'coincidencia' => 'mismo']];
    }
    $valor = fn (string $clave, $defecto = null) => old($clave, $defecto);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ $editando ? __('Editar cotización') : __('Nueva cotización') }} — {{ $meses[$mes] }} {{ $anio }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" enctype="multipart/form-data"
                  action="{{ $editando ? route('insumos.cotizaciones.update', $cotizacion) : route('insumos.cotizaciones.store') }}"
                  x-data='{ anio: {{ $anio }}, mes: {{ $mes }}, urlIa: @json(route("insumos.cotizaciones.leer-ia")), iaDisponible: @json($iaDisponible), leyendo: false, errorIa: "", leidaIa: {{ ($editando && $cotizacion->leida_con_ia) || old("leida_con_ia") ? "true" : "false" }},
                      proveedor: @json($valor("proveedor", $cotizacion?->proveedor)), folio: @json($valor("folio", $cotizacion?->folio)), fecha: @json($valor("fecha", $cotizacion?->fecha?->format("Y-m-d"))),
                      subtotal: @json($valor("subtotal", $cotizacion?->subtotal)), iva: @json($valor("iva", $cotizacion?->iva)), total: @json($valor("total", $cotizacion?->total)),
                      partidas: @json($partidas), renglones: @json($renglonesIniciales),
                      agregar() { this.renglones.push({partida_id: null, clave: null, descripcion: "", cantidad: "", precio_unitario: null, importe: null, coincidencia: "mismo"}) },
                      quitar(i) { if (this.renglones.length > 1) this.renglones.splice(i, 1) },
                      partida(id) { return this.partidas.find(p => p.id == id) },
                      aplicar(d) { this.proveedor = d.proveedor || this.proveedor; this.folio = d.folio || this.folio; this.fecha = d.fecha || this.fecha; this.subtotal = d.subtotal; this.iva = d.iva; this.total = d.total; if (d.renglones.length) { this.renglones = d.renglones.map(r => ({partida_id: r.partida_id, clave: r.clave, descripcion: r.descripcion, cantidad: r.cantidad, precio_unitario: r.precio_unitario, importe: r.importe, coincidencia: "mismo"})) } this.leidaIa = true },
                      async leerIa() { const archivo = this.$refs.archivo.files[0]; if (!archivo) { this.errorIa = "Elige primero el PDF de la cotización."; return } this.leyendo = true; this.errorIa = ""; const datos = new FormData(); datos.append("archivo", archivo); datos.append("anio", this.anio); datos.append("mes", this.mes); try { const r = await fetch(this.urlIa, {method: "POST", body: datos, headers: {"X-CSRF-TOKEN": document.querySelector("meta[name=csrf-token]").content, "Accept": "application/json"}}); const j = await r.json(); if (!r.ok || !j.success) { this.errorIa = (j.error && j.error.message) || j.message || "No se pudo leer el PDF."; return } this.aplicar(j.data) } catch (e) { this.errorIa = "No se pudo conectar con la IA. Captura la cotización manualmente." } finally { this.leyendo = false } } }'
                  class="space-y-6">
                @csrf
                @if ($editando) @method('PUT') @endif
                <input type="hidden" name="anio" :value="anio">
                <input type="hidden" name="mes" :value="mes">
                <input type="hidden" name="leida_con_ia" :value="leidaIa ? 1 : 0">

                <datalist id="lista-proveedores">
                    @foreach ($proveedores as $nombre)<option value="{{ $nombre }}"></option>@endforeach
                </datalist>

                <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <x-input-label for="archivo" :value="$editando && $cotizacion->archivo_ruta ? __('Reemplazar el PDF (opcional)') : __('PDF de la cotización')" />
                            <input id="archivo" name="archivo" type="file" accept=".pdf" x-ref="archivo" class="mt-1 block w-full text-sm text-gray-700">
                        </div>
                        <div class="flex items-end">
                            <button type="button" @click="leerIa()" :disabled="leyendo || !iaDisponible"
                                    class="inline-flex min-h-[44px] w-full items-center justify-center rounded-md border border-brand-teal px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist disabled:cursor-not-allowed disabled:opacity-50">
                                <span x-text="leyendo ? '{{ __('Leyendo…') }}' : '{{ __('Leer con IA') }}'"></span>
                            </button>
                        </div>
                    </div>
                    <p x-show="!iaDisponible" class="mt-2 text-xs text-gray-500">{{ __('La lectura con IA no está configurada en este servidor; captura la cotización a mano.') }}</p>
                    <p x-show="errorIa" x-text="errorIa" class="mt-2 text-sm text-red-600"></p>
                    <p x-show="leidaIa" class="mt-2 text-sm text-blue-700">{{ __('La IA propuso estos datos. Revísalos y corrígelos antes de guardar: nada se aplica a las partidas hasta que guardes.') }}</p>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="proveedor" :value="__('Proveedor')" />
                            <input id="proveedor" name="proveedor" type="text" required maxlength="150" list="lista-proveedores" x-model="proveedor" class="{{ $campo }}">
                        </div>
                        <div>
                            <x-input-label for="folio" :value="__('Folio de la cotización')" />
                            <input id="folio" name="folio" type="text" required maxlength="50" x-model="folio" class="{{ $campo }}">
                        </div>
                        <div>
                            <x-input-label for="fecha" :value="__('Fecha')" />
                            <input id="fecha" name="fecha" type="date" x-model="fecha" class="{{ $campo }}">
                        </div>
                        <div>
                            <x-input-label for="subtotal" :value="__('Subtotal')" />
                            <input id="subtotal" name="subtotal" type="number" step="0.01" min="0" x-model="subtotal" class="{{ $campo }}">
                        </div>
                        <div>
                            <x-input-label for="iva" :value="__('IVA')" />
                            <input id="iva" name="iva" type="number" step="0.01" min="0" x-model="iva" class="{{ $campo }}">
                        </div>
                        <div>
                            <x-input-label for="total" :value="__('Total')" />
                            <input id="total" name="total" type="number" step="0.01" min="0" x-model="total" class="{{ $campo }}">
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">{{ __('Subtotal, IVA y total son opcionales; con ellos el sistema valida que la cotización cuadre antes de aplicar sus precios.') }}</p>
                </div>

                <div class="space-y-3">
                    <template x-for="(r, i) in renglones" :key="i">
                        <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5">
                            <div class="grid grid-cols-1 gap-3 lg:grid-cols-12">
                                <div class="lg:col-span-5">
                                    <label class="block text-xs font-medium text-gray-600">{{ __('Partida solicitada') }}</label>
                                    <select :name="`renglones[${i}][partida_id]`" x-model="r.partida_id" class="{{ $campo }}">
                                        <option value="">{{ __('— Sin partida (cotizado fuera del documento) —') }}</option>
                                        <template x-for="p in partidas" :key="p.id"><option :value="p.id" x-text="p.etiqueta"></option></template>
                                    </select>
                                    <p x-show="partida(r.partida_id)" class="mt-1 text-xs text-gray-500"
                                       x-text="partida(r.partida_id) ? 'Solicitado: ' + partida(r.partida_id).cantidad + ' pzas · estimado ' + (partida(r.partida_id).precio === null ? 'sin precio' : '$' + partida(r.partida_id).precio) : ''"></p>
                                </div>
                                <div class="lg:col-span-4">
                                    <label class="block text-xs font-medium text-gray-600">{{ __('Descripción cotizada') }}</label>
                                    <input type="text" required maxlength="255" :name="`renglones[${i}][descripcion]`" x-model="r.descripcion" class="{{ $campo }}">
                                </div>
                                <div class="lg:col-span-3">
                                    <label class="block text-xs font-medium text-gray-600">{{ __('Clave / SKU') }}</label>
                                    <input type="text" maxlength="50" :name="`renglones[${i}][clave]`" x-model="r.clave" class="{{ $campo }}">
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600">{{ __('Cantidad') }}</label>
                                    <input type="number" step="0.01" min="0.01" required :name="`renglones[${i}][cantidad]`" x-model="r.cantidad" class="{{ $campo }}">
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600">{{ __('Precio unitario') }}</label>
                                    <input type="number" step="0.01" min="0" :name="`renglones[${i}][precio_unitario]`" x-model="r.precio_unitario" class="{{ $campo }}">
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600">{{ __('Importe') }}</label>
                                    <input type="number" step="0.01" min="0" :name="`renglones[${i}][importe]`" x-model="r.importe" class="{{ $campo }}">
                                </div>
                                <div class="lg:col-span-4">
                                    <label class="block text-xs font-medium text-gray-600">{{ __('¿Es lo mismo que se pidió?') }}</label>
                                    <select :name="`renglones[${i}][coincidencia]`" x-model="r.coincidencia" class="{{ $campo }}">
                                        <option value="mismo">{{ __('Sí, el mismo producto') }}</option>
                                        <option value="otra_marca">{{ __('Otra marca, modelo o presentación') }}</option>
                                        <option value="distinto">{{ __('Es un producto distinto') }}</option>
                                    </select>
                                </div>
                                <div class="flex items-end lg:col-span-2">
                                    <button type="button" @click="quitar(i)" class="min-h-[44px] text-sm text-red-600 hover:underline">{{ __('Quitar renglón') }}</button>
                                </div>
                            </div>
                        </div>
                    </template>
                    <button type="button" @click="agregar()" class="min-h-[44px] text-sm text-brand-teal hover:underline">+ {{ __('Agregar renglón') }}</button>
                </div>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <a href="{{ $editando ? route('insumos.cotizaciones.show', $cotizacion) : route('insumos.cotizaciones.index', ['anio' => $anio, 'mes' => $mes]) }}"
                       class="inline-flex min-h-[44px] items-center justify-center text-sm text-gray-600 hover:text-brand-green sm:self-center">{{ __('Cancelar') }}</a>
                    <x-primary-button type="submit">{{ __('Guardar cotización') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
