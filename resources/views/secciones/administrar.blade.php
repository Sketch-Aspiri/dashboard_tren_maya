<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green">{{ __('Administrar menú') }}</h2>
    </x-slot>

    <div class="py-6 sm:py-10">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            @include('secciones.partials.mensajes')

            <p class="text-sm text-gray-600">
                {{ __('Crea, renombra, ordena u oculta las secciones de cada grupo del menú. Quitar una sección la saca del menú; su información se conserva en la base de datos y, si necesitas recuperarla, pídelo al equipo de sistemas.') }}
            </p>

            @php
                $conSubsecciones = $grupos
                    ->flatMap(fn ($grupo) => \App\Support\ArbolSecciones::aplanar(\App\Support\ArbolSecciones::construir($grupo->secciones)))
                    ->filter(fn (array $nodo) => $nodo['seccion']->getRelation('hijas')->isNotEmpty())
                    ->map(fn (array $nodo) => $nodo['seccion']->id)
                    ->values();
            @endphp

            {{-- Compactar / buscar: las ramas con subsecciones arrancan cerradas. --}}
            <div x-data="{
                    todas: @js($conSubsecciones),
                    abiertas: [],
                    q: '',
                    init() {
                        try { this.abiertas = JSON.parse(sessionStorage.getItem('menu-abiertas') ?? '[]'); } catch (e) { this.abiertas = []; }
                        const destino = document.getElementById(location.hash.slice(1));
                        if (destino && destino.dataset.ancestros) {
                            this.abiertas = [...new Set([...this.abiertas, ...JSON.parse(destino.dataset.ancestros)])];
                            this.$nextTick(() => destino.scrollIntoView({ block: 'center' }));
                        }
                    },
                    guardar() { try { sessionStorage.setItem('menu-abiertas', JSON.stringify(this.abiertas)); } catch (e) {} },
                    alternar(id) {
                        this.abiertas = this.abiertas.includes(id) ? this.abiertas.filter(x => x !== id) : [...this.abiertas, id];
                        this.guardar();
                    },
                    abrirTodas() { this.abiertas = [...this.todas]; this.guardar(); },
                    cerrarTodas() { this.abiertas = []; this.guardar(); },
                    fold(texto) { return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
                    visible(ancestros, nombre) {
                        const q = this.fold(this.q.trim());
                        return q ? this.fold(nombre).includes(q) : ancestros.every(id => this.abiertas.includes(id));
                    },
                }" class="space-y-5">

            @if ($conSubsecciones->isNotEmpty() || $grupos->sum(fn ($g) => $g->secciones->count()) > 8)
                <div class="flex flex-col gap-2 rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <label for="buscar-seccion" class="block text-sm font-medium text-gray-700">{{ __('Buscar sección o subsección') }}</label>
                        <input id="buscar-seccion" type="search" x-model="q" autocomplete="off" placeholder="{{ __('Escribe parte del nombre…') }}"
                               class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                    </div>
                    <div class="flex gap-2" x-show="! q.trim()">
                        <button type="button" @click="abrirTodas()" class="min-h-[44px] rounded-md px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Expandir todo') }}</button>
                        <button type="button" @click="cerrarTodas()" class="min-h-[44px] rounded-md px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Compactar todo') }}</button>
                    </div>
                </div>
            @endif

            {{-- Nueva sección --}}
            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5">
                <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('Nueva sección') }}</h3>
                <form method="POST" action="{{ route('secciones.store') }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @csrf
                    <div>
                        <label for="nueva-nombre" class="block text-sm font-medium text-gray-700">{{ __('Nombre') }}</label>
                        <input id="nueva-nombre" name="nombre" required maxlength="160" value="{{ old('nombre') }}"
                               class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                    </div>
                    <div>
                        <label for="nueva-grupo" class="block text-sm font-medium text-gray-700">{{ __('Grupo del menú') }}</label>
                        <select id="nueva-grupo" name="menu_grupo_id" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm">
                            @foreach ($grupos as $grupo)
                                <option value="{{ $grupo->id }}" @selected((int) old('menu_grupo_id') === $grupo->id)>{{ $grupo->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <x-primary-button class="w-full sm:w-auto">{{ __('Crear sección') }}</x-primary-button>
                    </div>
                    <div class="sm:col-span-3">
                        <label for="nueva-descripcion" class="block text-sm font-medium text-gray-700">{{ __('Descripción (opcional)') }}</label>
                        <input id="nueva-descripcion" name="descripcion" maxlength="2000" value="{{ old('descripcion') }}"
                               class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                    </div>
                </form>
            </div>

            {{-- Grupos y sus secciones --}}
            @foreach ($grupos as $grupo)
                <div id="grupo-{{ $grupo->id }}" class="scroll-mt-24 overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5" x-data="{ renombrando: false }">
                    <div class="flex flex-col gap-2 border-b border-gray-100 bg-brand-mist/60 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div x-show="! renombrando">
                            <h3 class="font-heading text-base font-semibold text-brand-green">{{ $grupo->nombre }}</h3>
                            <p class="text-xs text-gray-500">{{ trans_choice(':count sección|:count secciones', $grupo->secciones->count()) }}</p>
                        </div>
                        <form method="POST" action="{{ route('secciones.grupos.update', $grupo) }}" x-show="renombrando" x-cloak class="flex flex-1 gap-2">
                            @csrf
                            @method('PUT')
                            <label for="grupo-{{ $grupo->id }}" class="sr-only">{{ __('Nombre del grupo') }}</label>
                            <input id="grupo-{{ $grupo->id }}" name="nombre" value="{{ $grupo->nombre }}" required maxlength="120"
                                   class="min-h-[40px] w-full rounded-md border-gray-300 text-sm shadow-sm sm:max-w-xs">
                            <x-primary-button>{{ __('Guardar') }}</x-primary-button>
                            <button type="button" @click="renombrando = false" class="rounded-md px-3 text-sm text-gray-700 hover:bg-gray-100">{{ __('Cancelar') }}</button>
                        </form>
                        <div class="flex flex-wrap items-center gap-1" x-show="! renombrando">
                            @foreach (['arriba' => '↑', 'abajo' => '↓'] as $direccion => $flecha)
                                <form method="POST" action="{{ route('secciones.grupos.mover', $grupo) }}">
                                    @csrf
                                    <input type="hidden" name="direccion" value="{{ $direccion }}">
                                    <button type="submit" @disabled(($direccion === 'arriba' && $loop->parent->first) || ($direccion === 'abajo' && $loop->parent->last)) class="min-h-[44px] min-w-[44px] rounded-md text-gray-600 hover:bg-white disabled:opacity-30" aria-label="{{ __('Mover el grupo :nombre hacia :dir', ['nombre' => $grupo->nombre, 'dir' => $direccion]) }}"><span aria-hidden="true">{{ $flecha }}</span></button>
                                </form>
                            @endforeach
                            <button type="button" @click="renombrando = true" class="min-h-[36px] rounded-md px-2 text-sm font-medium text-brand-teal hover:bg-white">{{ __('Renombrar') }}</button>
                            @if ($grupo->secciones->isEmpty())
                                <x-confirmar-eliminar :action="route('secciones.grupos.destroy', $grupo)" />
                            @endif
                        </div>
                    </div>

                    <ul class="divide-y divide-gray-100">
                        @php($raices = \App\Support\ArbolSecciones::construir($grupo->secciones))
                        @forelse ($raices as $seccion)
                            @include('secciones.partials.nodo-administrar', ['seccion' => $seccion, 'nivel' => 0, 'primera' => $loop->first, 'ultima' => $loop->last])
                        @empty
                            <li class="px-5 py-4 text-sm text-gray-500">{{ __('Sin secciones.') }}</li>
                        @endforelse
                    </ul>
                </div>
            @endforeach

            {{-- Nuevo grupo --}}
            <div class="rounded-xl border border-dashed border-brand-green/25 bg-white p-5">
                <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('Nuevo grupo del menú') }}</h3>
                <form method="POST" action="{{ route('secciones.grupos.store') }}" class="mt-3 flex flex-col gap-2 sm:flex-row">
                    @csrf
                    <label for="nuevo-grupo" class="sr-only">{{ __('Nombre del grupo') }}</label>
                    <input id="nuevo-grupo" name="nombre" required maxlength="120" placeholder="{{ __('Nombre del grupo') }}"
                           class="min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm sm:max-w-sm">
                    <x-primary-button>{{ __('Crear grupo') }}</x-primary-button>
                </form>
            </div>
            </div>{{-- /x-data compactar --}}
        </div>
    </div>
</x-app-layout>
