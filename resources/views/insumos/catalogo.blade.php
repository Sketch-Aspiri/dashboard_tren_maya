@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\CatalogoInsumo> $insumos */
    /** @var string $termino */
    /** @var bool $verInactivos */
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Catálogo de insumos')"
                          :detalle="trans_choice(':n insumo|:n insumos', $insumos->total(), ['n' => $insumos->total()]).($termino !== '' ? ' · '.__('filtrado por «:t»', ['t' => $termino]) : '')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.nav activo="catalogo" />

            <x-insumos.avisos />

            <div class="flex flex-wrap items-end justify-between gap-3">
                <form method="GET" action="{{ route('insumos.catalogo.index') }}" class="flex flex-wrap items-end gap-3" role="search">
                    <div class="w-full sm:w-80">
                        <label for="q-catalogo" class="sr-only">{{ __('Buscar en el catálogo') }}</label>
                        <input id="q-catalogo" name="q" type="search" class="ins-campo" value="{{ $termino }}" maxlength="100" placeholder="{{ __('Buscar por descripción…') }}">
                    </div>
                    <label class="flex min-h-[44px] items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="inactivos" value="1" @checked($verInactivos) class="rounded border-gray-300 text-brand-teal focus:ring-brand-teal" onchange="this.form.submit()">
                        {{ __('Mostrar inactivos') }}
                    </label>
                    <button type="submit" class="ins-btn-neutro">{{ __('Buscar') }}</button>
                    @if ($termino !== '' || $verInactivos)
                        <a href="{{ route('insumos.catalogo.index') }}" class="ins-enlace">{{ __('Quitar filtros') }}</a>
                    @endif
                </form>

                {{-- Si el alta manual falló la validación, se reabre con lo capturado. --}}
                <div class="flex flex-wrap gap-2" x-data @if ($errors->any() && old('descripcion') !== null && old('_method') === null) x-init="$refs.nuevo.showModal()" @endif>
                    <button type="button" class="ins-btn-secundario" @click="$refs.importar.showModal()">{{ __('Cargar ANEXO') }}</button>
                    <button type="button" class="ins-btn-primario" @click="$refs.nuevo.showModal(); $nextTick(() => document.getElementById('nuevo-descripcion').focus())">+ {{ __('Agregar insumo') }}</button>

                    {{-- Carga desde un ANEXO mensual en Excel. --}}
                    <dialog x-ref="importar" class="w-[min(32rem,calc(100vw-2rem))] rounded-xl p-0 shadow-xl backdrop:bg-gray-900/40" aria-labelledby="importar-titulo">
                        <form method="POST" action="{{ route('insumos.catalogo.importar') }}" enctype="multipart/form-data" class="p-6">
                            @csrf
                            <h3 id="importar-titulo" class="ins-titulo">{{ __('Cargar desde un ANEXO (.xlsx)') }}</h3>
                            <p class="ins-ayuda mt-1">{{ __('Lee la hoja ZO_Cotización: descripción, unidad, precio unitario y partida presupuestal. Agrega los insumos nuevos y actualiza el precio de los que ya existen.') }}</p>
                            <label for="archivo" class="sr-only">{{ __('Archivo') }}</label>
                            <input id="archivo" name="archivo" type="file" required accept=".xlsx" class="ins-archivo mt-4">
                            <div class="mt-6 flex justify-end gap-2">
                                <button type="button" class="ins-btn-neutro" @click="$refs.importar.close()">{{ __('Cancelar') }}</button>
                                <button type="submit" class="ins-btn-primario">{{ __('Cargar') }}</button>
                            </div>
                        </form>
                    </dialog>

                    {{-- Alta manual. --}}
                    <dialog x-ref="nuevo" class="w-[min(36rem,calc(100vw-2rem))] rounded-xl p-0 shadow-xl backdrop:bg-gray-900/40" aria-labelledby="nuevo-titulo">
                        <form method="POST" action="{{ route('insumos.catalogo.store') }}" class="p-6">
                            @csrf
                            <h3 id="nuevo-titulo" class="ins-titulo">{{ __('Agregar insumo') }}</h3>
                            <div class="mt-4 grid grid-cols-2 gap-3">
                                <div class="col-span-2">
                                    <x-input-label for="nuevo-descripcion" :value="__('Descripción')" />
                                    <input id="nuevo-descripcion" name="descripcion" type="text" required maxlength="255" class="ins-campo mt-1" value="{{ old('descripcion') }}">
                                </div>
                                <div>
                                    <x-input-label for="nuevo-unidad" :value="__('Unidad')" />
                                    <input id="nuevo-unidad" name="unidad" type="text" required maxlength="30" class="ins-campo mt-1" value="{{ old('unidad', 'Pieza') }}">
                                </div>
                                <div>
                                    <x-input-label for="nuevo-precio" :value="__('Precio unitario')" />
                                    <input id="nuevo-precio" name="ultimo_precio" type="number" inputmode="decimal" step="0.01" min="0" class="ins-campo mt-1" value="{{ old('ultimo_precio') }}">
                                </div>
                                <div>
                                    <x-input-label for="nuevo-partida" :value="__('Partida presupuestal')" />
                                    <input id="nuevo-partida" name="partida_presupuestal" type="text" inputmode="numeric" maxlength="5" pattern="\d{5}" placeholder="24901" class="ins-campo mt-1" value="{{ old('partida_presupuestal') }}">
                                </div>
                                <div>
                                    <x-input-label for="nuevo-proveedor" :value="__('Proveedor')" />
                                    <input id="nuevo-proveedor" name="proveedor" type="text" maxlength="255" class="ins-campo mt-1" value="{{ old('proveedor') }}">
                                </div>
                            </div>
                            <input type="hidden" name="activo" value="1">
                            <div class="mt-6 flex justify-end gap-2">
                                <button type="button" class="ins-btn-neutro" @click="$refs.nuevo.close()">{{ __('Cancelar') }}</button>
                                <button type="submit" class="ins-btn-primario">{{ __('Agregar') }}</button>
                            </div>
                        </form>
                    </dialog>
                </div>
            </div>

            <p class="text-xs text-gray-500">{{ __('Edita directamente en la tabla; cada renglón se guarda con su botón. Los insumos sin partida se resaltan.') }}</p>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Insumos del catálogo') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Descripción') }}</th>
                            <th scope="col">{{ __('Unidad') }}</th>
                            <th scope="col">{{ __('Código') }}</th>
                            <th scope="col">{{ __('Precio unitario') }}</th>
                            <th scope="col">{{ __('Partida') }}</th>
                            <th scope="col">{{ __('Proveedor') }}</th>
                            <th scope="col">{{ __('Activo') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($insumos as $insumo)
                            @php $formId = 'insumo-'.$insumo->id; $sinPartida = blank($insumo->partida_presupuestal); @endphp
                            <tr x-data="{ cambiado: false }" @input="cambiado = true" class="{{ $insumo->activo ? '' : 'opacity-60' }}">
                                <td><input form="{{ $formId }}" name="descripcion" type="text" required maxlength="255" class="ins-campo min-w-[18rem]" aria-label="{{ __('Descripción') }}" value="{{ $insumo->descripcion }}"></td>
                                <td><input form="{{ $formId }}" name="unidad" type="text" required maxlength="30" class="ins-campo w-24" aria-label="{{ __('Unidad') }}" value="{{ $insumo->unidad }}"></td>
                                <td><input form="{{ $formId }}" name="codigo" type="text" maxlength="50" class="ins-campo w-28" aria-label="{{ __('Código') }}" value="{{ $insumo->codigo }}"></td>
                                <td><input form="{{ $formId }}" name="ultimo_precio" type="number" inputmode="decimal" step="0.01" min="0" class="ins-campo w-28 text-right" aria-label="{{ __('Precio unitario') }}" value="{{ $insumo->ultimo_precio }}"></td>
                                <td><input form="{{ $formId }}" name="partida_presupuestal" type="text" inputmode="numeric" maxlength="5" pattern="\d{5}" class="ins-campo w-24 {{ $sinPartida ? '!border-amber-400 bg-amber-50' : '' }}" aria-label="{{ __('Partida presupuestal') }}" placeholder="{{ __('Falta') }}" value="{{ $insumo->partida_presupuestal }}"></td>
                                <td><input form="{{ $formId }}" name="proveedor" type="text" maxlength="255" class="ins-campo w-44" aria-label="{{ __('Proveedor') }}" value="{{ $insumo->proveedor }}"></td>
                                <td class="text-center">
                                    <input form="{{ $formId }}" type="checkbox" name="activo" value="1" @checked($insumo->activo) aria-label="{{ __('Activo') }}" class="mt-2.5 h-5 w-5 rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                                </td>
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        <form id="{{ $formId }}" method="POST" action="{{ route('insumos.catalogo.update', $insumo) }}">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="ins-btn !min-h-[36px] !px-3" :class="cambiado ? 'bg-brand-green text-white hover:bg-brand-green-dark' : 'text-brand-teal hover:bg-brand-mist'">{{ __('Guardar') }}</button>
                                        </form>
                                        <x-confirmar-eliminar :action="route('insumos.catalogo.destroy', $insumo)" :pregunta="__('¿Eliminar?')" :aria-label="__('Eliminar :d', ['d' => $insumo->descripcion])" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-insumos.vacio columnas="8" :titulo="$termino !== '' ? __('Ningún insumo coincide con «:t».', ['t' => $termino]) : __('No hay insumos en el catálogo.')">{{ __('Carga un ANEXO o agrega uno manualmente.') }}</x-insumos.vacio>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $insumos->links() }}
        </div>
    </div>
</x-app-layout>
