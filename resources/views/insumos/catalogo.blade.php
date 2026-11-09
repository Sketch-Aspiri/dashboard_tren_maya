@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\CatalogoInsumo> $insumos */
    /** @var string $termino */
    /** @var bool $verInactivos */
    $campo = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal text-sm';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Recursos materiales') }} — {{ __('Catálogo de insumos') }}
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

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Carga desde un ANEXO mensual en Excel. --}}
                <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                    <h3 class="font-semibold text-brand-green">{{ __('Cargar desde un ANEXO (.xlsx)') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Lee la hoja ZO_Cotización: descripción, unidad, precio unitario y partida presupuestal. Agrega los insumos nuevos y actualiza el precio de los que ya existen.') }}
                    </p>
                    <form method="POST" action="{{ route('insumos.catalogo.importar') }}" enctype="multipart/form-data" class="mt-4 flex flex-wrap items-end gap-2">
                        @csrf
                        <div class="grow">
                            <x-input-label for="archivo" :value="__('Archivo')" />
                            <input id="archivo" name="archivo" type="file" required accept=".xlsx"
                                   class="mt-1 block w-full text-sm text-gray-700">
                        </div>
                        <x-secondary-button type="submit">{{ __('Cargar') }}</x-secondary-button>
                    </form>
                </div>

                {{-- Alta manual. --}}
                <form method="POST" action="{{ route('insumos.catalogo.store') }}"
                      class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                    @csrf
                    <h3 class="font-semibold text-brand-green">{{ __('Agregar insumo') }}</h3>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="col-span-2">
                            <x-input-label for="nuevo-descripcion" :value="__('Descripción')" />
                            <input id="nuevo-descripcion" name="descripcion" type="text" required maxlength="255" class="{{ $campo }}" value="{{ old('descripcion') }}">
                        </div>
                        <div>
                            <x-input-label for="nuevo-unidad" :value="__('Unidad')" />
                            <input id="nuevo-unidad" name="unidad" type="text" required maxlength="30" class="{{ $campo }}" value="{{ old('unidad', 'Pieza') }}">
                        </div>
                        <div>
                            <x-input-label for="nuevo-precio" :value="__('Precio unitario')" />
                            <input id="nuevo-precio" name="ultimo_precio" type="number" step="0.01" min="0" class="{{ $campo }}" value="{{ old('ultimo_precio') }}">
                        </div>
                        <div>
                            <x-input-label for="nuevo-partida" :value="__('Partida presupuestal')" />
                            <input id="nuevo-partida" name="partida_presupuestal" type="text" maxlength="5" pattern="\d{5}" class="{{ $campo }}" value="{{ old('partida_presupuestal') }}">
                        </div>
                        <div>
                            <x-input-label for="nuevo-proveedor" :value="__('Proveedor')" />
                            <input id="nuevo-proveedor" name="proveedor" type="text" maxlength="255" class="{{ $campo }}" value="{{ old('proveedor') }}">
                        </div>
                    </div>
                    <input type="hidden" name="activo" value="1">
                    <div class="mt-4 flex justify-end">
                        <x-primary-button type="submit">{{ __('Agregar') }}</x-primary-button>
                    </div>
                </form>
            </div>

            <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="GET" action="{{ route('insumos.catalogo.index') }}" class="flex flex-wrap items-end gap-3">
                    <div class="grow max-w-md">
                        <x-input-label for="q-catalogo" :value="__('Buscar')" />
                        <x-text-input id="q-catalogo" name="q" type="text" class="block mt-1 w-full" value="{{ $termino }}" maxlength="100" />
                    </div>
                    <label class="flex min-h-[44px] items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="inactivos" value="1" @checked($verInactivos) class="rounded border-gray-300 text-brand-teal">
                        {{ __('Mostrar inactivos') }}
                    </label>
                    <x-secondary-button type="submit">{{ __('Filtrar') }}</x-secondary-button>
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <table class="min-w-full text-sm">
                    <thead class="bg-brand-mist text-left text-xs font-semibold uppercase tracking-wide text-brand-green">
                        <tr>
                            <th class="px-3 py-3">{{ __('Descripción') }}</th>
                            <th class="px-3 py-3">{{ __('Unidad') }}</th>
                            <th class="px-3 py-3">{{ __('Código') }}</th>
                            <th class="px-3 py-3">{{ __('Precio unitario') }}</th>
                            <th class="px-3 py-3">{{ __('Partida') }}</th>
                            <th class="px-3 py-3">{{ __('Proveedor') }}</th>
                            <th class="px-3 py-3">{{ __('Activo') }}</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($insumos as $insumo)
                            @php $formId = 'insumo-'.$insumo->id; @endphp
                            <tr class="align-top">
                                <td class="px-3 py-2"><input form="{{ $formId }}" name="descripcion" type="text" required maxlength="255" class="{{ $campo }} min-w-[16rem]" value="{{ $insumo->descripcion }}"></td>
                                <td class="px-3 py-2"><input form="{{ $formId }}" name="unidad" type="text" required maxlength="30" class="{{ $campo }} w-24" value="{{ $insumo->unidad }}"></td>
                                <td class="px-3 py-2"><input form="{{ $formId }}" name="codigo" type="text" maxlength="50" class="{{ $campo }} w-28" value="{{ $insumo->codigo }}"></td>
                                <td class="px-3 py-2"><input form="{{ $formId }}" name="ultimo_precio" type="number" step="0.01" min="0" class="{{ $campo }} w-28" value="{{ $insumo->ultimo_precio }}"></td>
                                <td class="px-3 py-2"><input form="{{ $formId }}" name="partida_presupuestal" type="text" maxlength="5" pattern="\d{5}" class="{{ $campo }} w-20" value="{{ $insumo->partida_presupuestal }}"></td>
                                <td class="px-3 py-2"><input form="{{ $formId }}" name="proveedor" type="text" maxlength="255" class="{{ $campo }} w-40" value="{{ $insumo->proveedor }}"></td>
                                <td class="px-3 py-2">
                                    <input form="{{ $formId }}" type="checkbox" name="activo" value="1" @checked($insumo->activo) class="mt-3 rounded border-gray-300 text-brand-teal">
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <form id="{{ $formId }}" method="POST" action="{{ route('insumos.catalogo.update', $insumo) }}" class="inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="min-h-[44px] px-2 text-brand-teal hover:underline">{{ __('Guardar') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('insumos.catalogo.destroy', $insumo) }}" class="inline"
                                          onsubmit="return confirm('{{ __('¿Eliminar este insumo del catálogo?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="min-h-[44px] px-2 text-red-600 hover:underline">{{ __('Eliminar') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-3 py-8 text-center text-gray-500">{{ __('No hay insumos en el catálogo. Carga un ANEXO o agrega uno manualmente.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $insumos->links() }}

            <div>
                <a href="{{ route('insumos.index') }}" class="text-sm text-gray-600 hover:text-brand-green">← {{ __('Volver') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
