@props(['id', 'items'])

{{-- Buscador de destinos del menú (Alpine.data('navBuscador') en
     resources/js/app.js). x-data va entre comillas SIMPLES porque @json()
     emite comillas dobles. --}}
<div {{ $attributes->merge(['class' => 'relative']) }}
     x-data='navBuscador(@json($items))' @click.outside="abierto = false">
    <label for="{{ $id }}" class="sr-only">{{ __('Buscar en el menú') }}</label>
    <svg class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-teal" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
    </svg>
    <x-text-input :id="$id" type="search"
                  class="block min-h-[44px] w-full border-brand-green/20 bg-brand-mist ps-9 text-sm placeholder:text-gray-500"
                  placeholder="{{ __('Buscar en el menú…') }}"
                  x-model="query"
                  @focus="abierto = true"
                  @input="abierto = true"
                  @keydown.escape="abierto = false; query = ''"
                  @keydown.enter.prevent="irAlPrimero()" />
    <ul x-show="abierto && resultados.length > 0" x-cloak
        class="absolute z-50 mt-1 w-full rounded-md border border-brand-green/10 bg-white py-1 shadow-lg">
        <template x-for="item in resultados" :key="item.href">
            <li>
                <a :href="item.href" x-text="item.label" @click="abierto = false"
                   class="block min-h-[44px] w-full px-4 py-2.5 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-brand-mist hover:text-brand-green focus:bg-brand-mist focus:outline-none"></a>
            </li>
        </template>
    </ul>
    <p x-show="abierto && query.trim() !== '' && resultados.length === 0" x-cloak
       class="absolute z-50 mt-1 w-full rounded-md border border-brand-green/10 bg-white px-4 py-2 text-sm text-gray-500 shadow-lg">
        {{ __('Sin resultados.') }}
    </p>
</div>
