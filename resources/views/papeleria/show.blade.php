@php
    /** @var \App\Models\SolicitudPapeleria $solicitud */
    /** @var bool $puedeEditar */
    /** @var bool $puedeRevisar */
    /** @var \Illuminate\Support\Collection<int, string> $articulos */
    /** @var list<string> $unidades */
    $nombresMes = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    $filasIniciales = old('partidas', $solicitud->partidas->map(fn ($p) => [
        'articulo' => $p->articulo,
        'unidad' => $p->unidad,
        'cantidad' => $p->cantidad + 0,
        'comentarios' => $p->comentarios,
    ])->values()->all());
    if (empty($filasIniciales)) {
        $filasIniciales = [['articulo' => '', 'unidad' => $unidades[0], 'cantidad' => '', 'comentarios' => '']];
    }
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="$solicitud->estacion->nombre.' — '.$nombresMes[$solicitud->mes].' '.$solicitud->anio"
                          :detalle="__('Solicitud de papelería')" :seccion="__('Papelería')"
                          :volver="$puedeEditar ? null : route('papeleria.index', ['anio' => $solicitud->anio, 'mes' => $solicitud->mes])"
                          :volver-texto="__('Solicitudes del mes')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-papeleria.nav activo="solicitudes" :anio="$solicitud->anio" :mes="$solicitud->mes" />

            <x-insumos.avisos />

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
                @if ($solicitud->enviada_at)
                    <span>{{ __('Enviada') }}: <span class="font-medium text-gray-900">{{ $solicitud->enviada_at->format('d/m/Y H:i') }}</span></span>
                @endif
                @if ($solicitud->revisor)
                    <span>{{ __('Revisó') }}: <span class="font-medium text-gray-900">{{ $solicitud->revisor->name }}</span></span>
                @endif
            </div>

            @if ($puedeEditar)
                <form id="form-solicitud" method="POST" action="{{ route('papeleria.update', $solicitud) }}"
                      x-data="solicitudPapeleria" data-unidades='@json($unidades)' data-filas='@json($filasIniciales)'
                      data-sucio="{{ old('partidas') !== null || $solicitud->partidas->isEmpty() ? '1' : '0' }}"
                      @input="sucio = true" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <section aria-labelledby="partidas-titulo" class="space-y-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 id="partidas-titulo" class="ins-titulo">{{ __('Artículos que solicitas') }} <span class="font-normal text-gray-500" x-text="'(' + filas.length + ')'"></span></h3>
                            <p class="text-xs text-gray-500">{{ __('Elige el artículo de la lista del catálogo, su unidad y la cantidad.') }}</p>
                        </div>

                        <datalist id="catalogo-papeleria">
                            @foreach ($articulos as $articulo)
                                <option value="{{ $articulo }}"></option>
                            @endforeach
                        </datalist>

                        <template x-for="(fila, i) in filas" :key="i">
                            <div class="ins-card p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Artículo') }} <span x-text="i + 1"></span></p>
                                    <button type="button" @click="quitar(i)" x-show="filas.length > 1" class="ins-enlace-peligro !min-h-[36px]" :aria-label="'{{ __('Quitar artículo') }} ' + (i + 1)">{{ __('Quitar') }}</button>
                                </div>
                                <div class="mt-2 grid grid-cols-2 gap-3 lg:grid-cols-12">
                                    <div class="col-span-2 lg:col-span-6">
                                        <label :for="`p-${i}-articulo`" class="ins-etiqueta">{{ __('Artículo') }}</label>
                                        <input :id="`p-${i}-articulo`" type="text" required maxlength="255" list="catalogo-papeleria" class="ins-campo mt-1"
                                               :name="`partidas[${i}][articulo]`" x-model="fila.articulo">
                                    </div>
                                    <div class="lg:col-span-3">
                                        <label :for="`p-${i}-unidad`" class="ins-etiqueta">{{ __('Unidad de medida') }}</label>
                                        <select :id="`p-${i}-unidad`" required class="ins-campo mt-1" :name="`partidas[${i}][unidad]`" x-model="fila.unidad">
                                            <template x-for="u in unidades" :key="u"><option :value="u" x-text="u"></option></template>
                                        </select>
                                    </div>
                                    <div class="lg:col-span-3">
                                        <label :for="`p-${i}-cantidad`" class="ins-etiqueta">{{ __('Cantidad') }}</label>
                                        <input :id="`p-${i}-cantidad`" type="number" inputmode="decimal" step="0.01" min="0.01" required class="ins-campo mt-1"
                                               :name="`partidas[${i}][cantidad]`" x-model="fila.cantidad">
                                    </div>
                                    <div class="col-span-2 lg:col-span-12">
                                        <label :for="`p-${i}-comentarios`" class="ins-etiqueta">{{ __('Comentarios') }}</label>
                                        <input :id="`p-${i}-comentarios`" type="text" maxlength="2000" class="ins-campo mt-1"
                                               :name="`partidas[${i}][comentarios]`" x-model="fila.comentarios">
                                    </div>
                                </div>
                            </div>
                        </template>

                        <button type="button" @click="agregar()" class="ins-btn-secundario w-full border-dashed sm:w-auto">+ {{ __('Agregar artículo') }}</button>
                    </section>

                    <div class="ins-barra-acciones">
                        <p class="text-sm" :class="sucio ? 'text-amber-700' : 'text-gray-500'" role="status"
                           x-text="sucio ? '{{ __('Tienes cambios sin guardar. Guarda antes de enviar.') }}' : '{{ __('Todo guardado. Cuando termines, envía la solicitud a la Zona.') }}'"></p>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row">
                            <button type="submit" class="ins-btn-secundario">{{ __('Guardar borrador') }}</button>
                            @if ($solicitud->partidas->isNotEmpty())
                                <button type="submit" form="form-enviar" class="ins-btn-primario" :disabled="sucio"
                                        :title="sucio ? '{{ __('Guarda primero los cambios') }}' : ''">{{ __('Enviar a la Zona') }}</button>
                            @endif
                        </div>
                    </div>
                </form>

                @if ($solicitud->partidas->isNotEmpty())
                    <form id="form-enviar" method="POST" action="{{ route('papeleria.enviar', $solicitud) }}" class="hidden">@csrf</form>
                @endif

                @push('scripts')
                    <script>
                        document.addEventListener('alpine:init', () => {
                            Alpine.data('solicitudPapeleria', () => ({
                                unidades: [], filas: [], sucio: false,
                                init() {
                                    const d = this.$el.dataset;
                                    this.unidades = JSON.parse(d.unidades);
                                    this.filas = JSON.parse(d.filas);
                                    this.sucio = d.sucio === '1';
                                },
                                agregar() {
                                    this.filas.push({ articulo: '', unidad: this.unidades[0], cantidad: '', comentarios: '' });
                                    this.sucio = true;
                                    this.$nextTick(() => document.getElementById(`p-${this.filas.length - 1}-articulo`)?.focus());
                                },
                                quitar(i) {
                                    if (this.filas.length > 1) { this.filas.splice(i, 1); this.sucio = true; }
                                },
                            }));
                        });
                    </script>
                @endpush
            @else
                <div class="ins-tabla-marco">
                    <table class="ins-tabla">
                        <caption class="sr-only">{{ __('Artículos de la solicitud') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('No.') }}</th>
                                <th scope="col">{{ __('Artículo') }}</th>
                                <th scope="col">{{ __('Unidad de medida') }}</th>
                                <th scope="col" class="!text-right">{{ __('Cantidad') }}</th>
                                <th scope="col">{{ __('Comentarios') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($solicitud->partidas as $partida)
                                <tr>
                                    <td>{{ $partida->numero }}</td>
                                    <td class="fuerte min-w-[14rem]">{{ $partida->articulo }}</td>
                                    <td>{{ $partida->unidad }}</td>
                                    <td class="num">{{ $partida->cantidad + 0 }}</td>
                                    <td class="text-gray-600">{{ $partida->comentarios ?: '—' }}</td>
                                </tr>
                            @empty
                                <x-insumos.vacio :columnas="5" :titulo="__('Sin artículos capturados')" />
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($puedeRevisar)
                    <form method="POST" action="{{ route('papeleria.revisar', $solicitud) }}" class="space-y-6">
                        @csrf
                        <section class="ins-card ins-card-pad">
                            <x-input-label for="comentario_zona" :value="__('Comentario para la estación (obligatorio al devolver)')" />
                            <textarea id="comentario_zona" name="comentario_zona" rows="3" class="ins-campo mt-1" placeholder="{{ __('Qué debe corregir la estación…') }}">{{ old('comentario_zona') }}</textarea>
                        </section>
                        <div class="ins-barra-acciones">
                            <p class="text-sm text-gray-500">{{ __('Al aprobar, la solicitud entra al Excel del mes.') }}</p>
                            <div class="flex flex-col-reverse gap-2 sm:flex-row">
                                <button type="submit" name="accion" value="devolver" class="ins-btn-peligro">{{ __('Devolver a la estación') }}</button>
                                <button type="submit" name="accion" value="aprobar" class="ins-btn-primario">{{ __('Aprobar') }}</button>
                            </div>
                        </div>
                    </form>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
