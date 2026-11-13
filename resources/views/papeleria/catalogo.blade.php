@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\CatalogoPapeleria> $articulos */
    /** @var string $termino */
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Catálogo de papelería')" :seccion="__('Papelería')"
                          :detalle="trans_choice(':n artículo|:n artículos', $articulos->total(), ['n' => $articulos->total()])" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-papeleria.nav activo="catalogo" />

            <x-insumos.avisos />

            <div class="flex flex-wrap items-end justify-between gap-3">
                <form method="GET" action="{{ route('papeleria.catalogo.index') }}" class="flex flex-wrap items-end gap-3" role="search">
                    <div class="w-full sm:w-80">
                        <label for="q-catalogo" class="sr-only">{{ __('Buscar en el catálogo') }}</label>
                        <input id="q-catalogo" name="q" type="search" class="ins-campo" value="{{ $termino }}" maxlength="100" placeholder="{{ __('Buscar artículo…') }}">
                    </div>
                    <button type="submit" class="ins-btn-neutro">{{ __('Buscar') }}</button>
                    @if ($termino !== '')
                        <a href="{{ route('papeleria.catalogo.index') }}" class="ins-enlace">{{ __('Quitar filtro') }}</a>
                    @endif
                </form>

                <form method="POST" action="{{ route('papeleria.catalogo.store') }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <div class="w-full sm:w-96">
                        <label for="nuevo-articulo" class="sr-only">{{ __('Nuevo artículo') }}</label>
                        <input id="nuevo-articulo" name="descripcion" type="text" required maxlength="255" class="ins-campo" placeholder="{{ __('Nuevo artículo…') }}" value="{{ old('descripcion') }}">
                    </div>
                    <button type="submit" class="ins-btn-primario">+ {{ __('Agregar') }}</button>
                </form>
            </div>

            <div class="ins-tabla-marco">
                <table class="ins-tabla">
                    <caption class="sr-only">{{ __('Artículos de papelería') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Artículo') }}</th>
                            <th scope="col">{{ __('Activo') }}</th>
                            <th scope="col" class="!text-right">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($articulos as $articulo)
                            <tr>
                                <td colspan="3" class="!p-0">
                                    <form method="POST" action="{{ route('papeleria.catalogo.update', $articulo) }}" class="flex flex-wrap items-center gap-3 px-3 py-2 sm:px-4">
                                        @csrf
                                        @method('PUT')
                                        <label class="sr-only" for="art-{{ $articulo->id }}">{{ __('Descripción') }}</label>
                                        <input id="art-{{ $articulo->id }}" name="descripcion" type="text" required maxlength="255" class="ins-campo min-w-0 flex-1" value="{{ $articulo->descripcion }}">
                                        <label class="flex min-h-[44px] items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" name="activo" value="1" @checked($articulo->activo) class="rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                                            {{ __('Activo') }}
                                        </label>
                                        <button type="submit" class="ins-btn-secundario !min-h-[36px] !px-3">{{ __('Guardar') }}</button>
                                        <button type="submit" form="borrar-{{ $articulo->id }}" class="ins-enlace-peligro !min-h-[36px]"
                                                onclick="return confirm('{{ __('¿Eliminar este artículo del catálogo?') }}')">{{ __('Eliminar') }}</button>
                                    </form>
                                    <form id="borrar-{{ $articulo->id }}" method="POST" action="{{ route('papeleria.catalogo.destroy', $articulo) }}" class="hidden">@csrf @method('DELETE')</form>
                                </td>
                            </tr>
                        @empty
                            <x-insumos.vacio :columnas="3" :titulo="__('Sin artículos')">{{ __('Carga el catálogo con: php artisan papeleria:cargar-catalogo') }}</x-insumos.vacio>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $articulos->links() }}
        </div>
    </div>
</x-app-layout>
