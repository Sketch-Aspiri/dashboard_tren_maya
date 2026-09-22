{{-- $navegacionBuscable (destinos del buscador del menú) se calcula en
     layouts/app.blade.php, no aquí: el buscador de escritorio ahora vive en
     la misma fila que el título de página (slot "header" de app.blade.php),
     así que ese layout necesita la variable tanto como esta vista. Blade
     comparte el scope de la vista padre hacia @include, así que definirla
     una sola vez en app.blade.php basta para ambos usos (este archivo solo
     la consume, en el buscador del menú de hamburguesa más abajo). --}}
<nav x-data="{ open: false }" class="bg-white border-b border-brand-green/10 shadow-sm">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2" aria-label="{{ config('app.name', 'Dashboard - Zona Oriente') }} — inicio">
                        <img src="{{ asset('logo.png') }}" alt="Tren Maya" class="h-9 w-auto">
                        <span class="hidden font-heading text-sm font-semibold leading-tight text-brand-green md:inline">
                            {{ __('Dashboard') }}<br class="hidden lg:block">
                            <span class="font-normal text-gray-500">{{ __('Zona Oriente') }}</span>
                        </span>
                    </a>
                </div>

                <!-- Navigation Links -->
                {{-- shrink-0: el buscador nuevo a la derecha nunca debe
                     comprimir estos enlaces — antes de añadirlo, "Agenda
                     Zona Oriente" y el resto siempre tenían su ancho
                     natural; sin shrink-0, flexbox los encoge y su texto
                     se parte en varias líneas cuando falta espacio. --}}
                <div class="hidden shrink-0 space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    @unlessrole('Estación')
                        <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            {{ __('Dashboard') }}
                        </x-nav-link>
                        {{-- "Agenda Zona Oriente" groups Personal and Rol de
                             vacaciones under one dropdown, same shape as the
                             "Asistencia" one below. --}}
                        @php
                            $agendaActiva = request()->routeIs('agenda.*');
                            $agendaTriggerClasses = $agendaActiva
                                ? 'inline-flex items-center px-1 pt-1 border-b-2 border-brand-teal text-sm font-semibold leading-5 text-brand-green focus:outline-none focus:border-brand-green transition duration-150 ease-in-out'
                                : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-brand-green hover:border-brand-mint focus:outline-none focus:text-brand-green focus:border-brand-mint transition duration-150 ease-in-out';
                        @endphp
                        <x-dropdown align="left" width="w-64" class="flex" trigger-classes="flex">
                            <x-slot name="trigger">
                                <button type="button" class="{{ $agendaTriggerClasses }}">
                                    {{ __('Agenda Zona Oriente') }}
                                    <svg class="ms-1 h-4 w-4 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <x-dropdown-link :href="route('agenda.personal.index')">
                                    {{ __('Personal') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('agenda.vacaciones.index')">
                                    {{ __('Rol de vacaciones') }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    @endunlessrole
                    @hasanyrole('Jefe de Zona|Administrador|Estación')
                        {{-- "Asistencia" groups the two links below under one
                             dropdown — the trigger shows for anyone who can
                             see at least one of them (union of both roles'
                             conditions); each x-dropdown-link keeps its own
                             original @hasanyrole guard, so a single-role user
                             (e.g. Estación) sees the trigger with only their
                             one applicable option inside. --}}
                        @php
                            $asistenciaActiva = request()->routeIs('asistencia.zona.*') || request()->routeIs('asistencia.captura.*');
                            $asistenciaTriggerClasses = $asistenciaActiva
                                ? 'inline-flex items-center px-1 pt-1 border-b-2 border-brand-teal text-sm font-semibold leading-5 text-brand-green focus:outline-none focus:border-brand-green transition duration-150 ease-in-out'
                                : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-brand-green hover:border-brand-mint focus:outline-none focus:text-brand-green focus:border-brand-mint transition duration-150 ease-in-out';
                        @endphp
                        {{-- class/trigger-classes "flex": let the trigger stretch to the
                             full nav height like the sibling <x-nav-link>s, so its label
                             and underline line up with theirs instead of sitting at the top. --}}
                        <x-dropdown align="left" width="w-64" class="flex" trigger-classes="flex">
                            <x-slot name="trigger">
                                <button type="button" class="{{ $asistenciaTriggerClasses }}">
                                    {{ __('Asistencia') }}
                                    <svg class="ms-1 h-4 w-4 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                @hasanyrole('Jefe de Zona|Administrador')
                                    <x-dropdown-link :href="route('asistencia.zona.index')">
                                        {{ __('Asistencia Zona Oriente') }}
                                    </x-dropdown-link>
                                @endhasanyrole
                                @hasanyrole('Estación|Administrador')
                                    {{-- Administrador can capture/correct any estación
                                         (not just the ones with their own login), so it
                                         needs a nav entry point too, not only Estación. --}}
                                    <x-dropdown-link :href="route('asistencia.captura.index')">
                                        {{ __('Captura de asistencia') }}
                                    </x-dropdown-link>
                                @endhasanyrole
                            </x-slot>
                        </x-dropdown>
                    @endhasanyrole
                    @hasanyrole('Jefe de Zona|Administrador|Estación')
                        {{-- "Estadísticas" groups Flujo de pasajeros and Gasto
                             energético under one dropdown — every role sees
                             both, each scoped to its own estación for
                             "Estación" accounts per ServicioEstacionPolicy. --}}
                        @php
                            $estadisticasActiva = request()->routeIs('estadisticas.*');
                            $estadisticasTriggerClasses = $estadisticasActiva
                                ? 'inline-flex items-center px-1 pt-1 border-b-2 border-brand-teal text-sm font-semibold leading-5 text-brand-green focus:outline-none focus:border-brand-green transition duration-150 ease-in-out'
                                : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-brand-green hover:border-brand-mint focus:outline-none focus:text-brand-green focus:border-brand-mint transition duration-150 ease-in-out';
                        @endphp
                        <x-dropdown align="left" width="w-64" class="flex" trigger-classes="flex">
                            <x-slot name="trigger">
                                <button type="button" class="{{ $estadisticasTriggerClasses }}">
                                    {{ __('Estadísticas') }}
                                    <svg class="ms-1 h-4 w-4 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <x-dropdown-link :href="route('estadisticas.index')">
                                    {{ __('Flujo de pasajeros') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('estadisticas.gasto-energetico.index')">
                                    {{ __('Gasto energético') }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    @endhasanyrole
                    @hasanyrole('Jefe de Zona|Administrador|Estación')
                        {{-- "Controles" groups Escaleras eléctricas y Elevadores
                             bajo un dropdown, mismo patrón que "Estadísticas".
                             Ambas páginas son placeholder hasta que llegue el
                             modelo de datos real (ver CLAUDE.md). --}}
                        @php
                            $controlesActiva = request()->routeIs('controles.*');
                            $controlesTriggerClasses = $controlesActiva
                                ? 'inline-flex items-center px-1 pt-1 border-b-2 border-brand-teal text-sm font-semibold leading-5 text-brand-green focus:outline-none focus:border-brand-green transition duration-150 ease-in-out'
                                : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-brand-green hover:border-brand-mint focus:outline-none focus:text-brand-green focus:border-brand-mint transition duration-150 ease-in-out';
                        @endphp
                        <x-dropdown align="left" width="w-64" class="flex" trigger-classes="flex">
                            <x-slot name="trigger">
                                <button type="button" class="{{ $controlesTriggerClasses }}">
                                    {{ __('Controles') }}
                                    <svg class="ms-1 h-4 w-4 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <x-dropdown-link :href="route('controles.escaleras-electricas.index')">
                                    {{ __('Escaleras eléctricas') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('controles.elevadores.index')">
                                    {{ __('Elevadores') }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    @endhasanyrole
                    @hasrole('Administrador')
                        <x-nav-link :href="route('usuarios.index')" :active="request()->routeIs('usuarios.*')">
                            {{ __('Usuarios') }}
                        </x-nav-link>
                    @endhasrole
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex min-h-[44px] items-center gap-1 rounded-md border border-transparent px-3 py-2 text-sm font-medium leading-4 text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open"
                        :aria-expanded="open.toString()"
                        aria-label="{{ __('Abrir menú de navegación') }}"
                        class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-md p-2 text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- El buscador de escritorio ya NO vive aquí como una tira propia
         debajo de la barra principal (eso era justo la queja de diseño:
         dos tiras delgadas apiladas, una para el buscador y otra para el
         título de página). Ahora se renderiza dentro de
         layouts/app.blade.php, en la misma fila que el slot "header", para
         leerse como una sola franja de encabezado. Ver ese archivo para el
         buscador de escritorio; abajo sigue la copia para el menú de
         hamburguesa en celular, sin cambios. --}}

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden border-t border-brand-green/10 sm:hidden">
        {{-- Mismo buscador que arriba (ver el bloque justo encima), a ancho
             completo para la barra de navegación en celular. --}}
        <div class="relative px-4 pt-3" x-data='navBuscador(@json($navegacionBuscable))' @click.outside="abierto = false">
            <label for="buscar-nav-movil" class="sr-only">{{ __('Buscar en el menú') }}</label>
            <x-text-input id="buscar-nav-movil" type="search"
                          class="block w-full text-sm"
                          placeholder="{{ __('Buscar en el menú…') }}"
                          x-model="query"
                          @focus="abierto = true"
                          @input="abierto = true"
                          @keydown.escape="abierto = false; query = ''"
                          @keydown.enter.prevent="irAlPrimero()" />
            <ul x-show="abierto && resultados.length > 0" x-cloak
                class="absolute z-50 left-4 right-4 mt-1 rounded-md border border-brand-green/10 bg-white py-1 shadow-lg">
                <template x-for="item in resultados" :key="item.href">
                    <li>
                        <a :href="item.href" x-text="item.label" @click="abierto = false"
                           class="block min-h-[44px] w-full items-center px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-brand-mist hover:text-brand-green focus:outline-none focus:bg-brand-mist transition duration-150 ease-in-out"></a>
                    </li>
                </template>
            </ul>
            <p x-show="abierto && query.trim() !== '' && resultados.length === 0" x-cloak
               class="absolute z-50 left-4 right-4 mt-1 rounded-md border border-brand-green/10 bg-white px-4 py-2 text-sm text-gray-500 shadow-lg">
                {{ __('Sin resultados.') }}
            </p>
        </div>
        <div class="space-y-1 pt-2 pb-3">
            @unlessrole('Estación')
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    {{ __('Dashboard') }}
                </x-responsive-nav-link>
                {{-- Same accordion shape as "Asistencia" below (own x-data
                     scope: "openAgenda"). --}}
                @php
                    $agendaActivaResponsive = request()->routeIs('agenda.*');
                    $agendaResponsiveTriggerClasses = $agendaActivaResponsive
                        ? 'flex items-center justify-between min-h-[44px] w-full ps-3 pe-4 py-2 border-l-4 border-brand-teal text-start text-base font-semibold text-brand-green bg-brand-mist focus:outline-none focus:text-brand-green-dark focus:bg-brand-mist focus:border-brand-green transition duration-150 ease-in-out'
                        : 'flex items-center justify-between min-h-[44px] w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-brand-green hover:bg-brand-mist hover:border-brand-mint focus:outline-none focus:text-brand-green focus:bg-brand-mist focus:border-brand-mint transition duration-150 ease-in-out';
                @endphp
                <div x-data="{ openAgenda: {{ $agendaActivaResponsive ? 'true' : 'false' }} }">
                    <button type="button" @click="openAgenda = ! openAgenda"
                            :aria-expanded="openAgenda.toString()"
                            class="{{ $agendaResponsiveTriggerClasses }}">
                        <span>{{ __('Agenda Zona Oriente') }}</span>
                        <svg class="h-4 w-4 fill-current transition-transform" :class="{ 'rotate-180': openAgenda }"
                             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div x-show="openAgenda" class="space-y-1 pl-4">
                        <x-responsive-nav-link :href="route('agenda.personal.index')" :active="request()->routeIs('agenda.personal.*')">
                            {{ __('Personal') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('agenda.vacaciones.index')" :active="request()->routeIs('agenda.vacaciones.*')">
                            {{ __('Rol de vacaciones') }}
                        </x-responsive-nav-link>
                    </div>
                </div>
            @endunlessrole
            @hasanyrole('Jefe de Zona|Administrador|Estación')
                {{-- Same "Asistencia" grouping as the desktop dropdown above,
                     as a local Alpine accordion (own x-data scope —
                     "openAsistencia", distinct from the hamburger menu's
                     "open" — so toggling one never affects the other). Each
                     option below keeps its own original @hasanyrole guard. --}}
                @php
                    $asistenciaActivaResponsive = request()->routeIs('asistencia.zona.*') || request()->routeIs('asistencia.captura.*');
                    $asistenciaResponsiveTriggerClasses = $asistenciaActivaResponsive
                        ? 'flex items-center justify-between min-h-[44px] w-full ps-3 pe-4 py-2 border-l-4 border-brand-teal text-start text-base font-semibold text-brand-green bg-brand-mist focus:outline-none focus:text-brand-green-dark focus:bg-brand-mist focus:border-brand-green transition duration-150 ease-in-out'
                        : 'flex items-center justify-between min-h-[44px] w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-brand-green hover:bg-brand-mist hover:border-brand-mint focus:outline-none focus:text-brand-green focus:bg-brand-mist focus:border-brand-mint transition duration-150 ease-in-out';
                @endphp
                <div x-data="{ openAsistencia: {{ $asistenciaActivaResponsive ? 'true' : 'false' }} }">
                    <button type="button" @click="openAsistencia = ! openAsistencia"
                            :aria-expanded="openAsistencia.toString()"
                            class="{{ $asistenciaResponsiveTriggerClasses }}">
                        <span>{{ __('Asistencia') }}</span>
                        <svg class="h-4 w-4 fill-current transition-transform" :class="{ 'rotate-180': openAsistencia }"
                             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div x-show="openAsistencia" class="space-y-1 pl-4">
                        @hasanyrole('Jefe de Zona|Administrador')
                            <x-responsive-nav-link :href="route('asistencia.zona.index')" :active="request()->routeIs('asistencia.zona.*')">
                                {{ __('Asistencia Zona Oriente') }}
                            </x-responsive-nav-link>
                        @endhasanyrole
                        @hasanyrole('Estación|Administrador')
                            <x-responsive-nav-link :href="route('asistencia.captura.index')" :active="request()->routeIs('asistencia.captura.*')">
                                {{ __('Captura de asistencia') }}
                            </x-responsive-nav-link>
                        @endhasanyrole
                    </div>
                </div>
            @endhasanyrole
            @hasanyrole('Jefe de Zona|Administrador|Estación')
                {{-- Same accordion shape as "Asistencia" (own x-data scope:
                     "openEstadisticas"). --}}
                @php
                    $estadisticasActivaResponsive = request()->routeIs('estadisticas.*');
                    $estadisticasResponsiveTriggerClasses = $estadisticasActivaResponsive
                        ? 'flex items-center justify-between min-h-[44px] w-full ps-3 pe-4 py-2 border-l-4 border-brand-teal text-start text-base font-semibold text-brand-green bg-brand-mist focus:outline-none focus:text-brand-green-dark focus:bg-brand-mist focus:border-brand-green transition duration-150 ease-in-out'
                        : 'flex items-center justify-between min-h-[44px] w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-brand-green hover:bg-brand-mist hover:border-brand-mint focus:outline-none focus:text-brand-green focus:bg-brand-mist focus:border-brand-mint transition duration-150 ease-in-out';
                @endphp
                <div x-data="{ openEstadisticas: {{ $estadisticasActivaResponsive ? 'true' : 'false' }} }">
                    <button type="button" @click="openEstadisticas = ! openEstadisticas"
                            :aria-expanded="openEstadisticas.toString()"
                            class="{{ $estadisticasResponsiveTriggerClasses }}">
                        <span>{{ __('Estadísticas') }}</span>
                        <svg class="h-4 w-4 fill-current transition-transform" :class="{ 'rotate-180': openEstadisticas }"
                             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div x-show="openEstadisticas" class="space-y-1 pl-4">
                        <x-responsive-nav-link :href="route('estadisticas.index')" :active="request()->routeIs('estadisticas.*') && ! request()->routeIs('estadisticas.gasto-energetico.*')">
                            {{ __('Flujo de pasajeros') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('estadisticas.gasto-energetico.index')" :active="request()->routeIs('estadisticas.gasto-energetico.*')">
                            {{ __('Gasto energético') }}
                        </x-responsive-nav-link>
                    </div>
                </div>
            @endhasanyrole
            @hasanyrole('Jefe de Zona|Administrador|Estación')
                {{-- Same accordion shape as "Estadísticas" (own x-data scope:
                     "openControles"). --}}
                @php
                    $controlesActivaResponsive = request()->routeIs('controles.*');
                    $controlesResponsiveTriggerClasses = $controlesActivaResponsive
                        ? 'flex items-center justify-between min-h-[44px] w-full ps-3 pe-4 py-2 border-l-4 border-brand-teal text-start text-base font-semibold text-brand-green bg-brand-mist focus:outline-none focus:text-brand-green-dark focus:bg-brand-mist focus:border-brand-green transition duration-150 ease-in-out'
                        : 'flex items-center justify-between min-h-[44px] w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-brand-green hover:bg-brand-mist hover:border-brand-mint focus:outline-none focus:text-brand-green focus:bg-brand-mist focus:border-brand-mint transition duration-150 ease-in-out';
                @endphp
                <div x-data="{ openControles: {{ $controlesActivaResponsive ? 'true' : 'false' }} }">
                    <button type="button" @click="openControles = ! openControles"
                            :aria-expanded="openControles.toString()"
                            class="{{ $controlesResponsiveTriggerClasses }}">
                        <span>{{ __('Controles') }}</span>
                        <svg class="h-4 w-4 fill-current transition-transform" :class="{ 'rotate-180': openControles }"
                             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div x-show="openControles" class="space-y-1 pl-4">
                        <x-responsive-nav-link :href="route('controles.escaleras-electricas.index')" :active="request()->routeIs('controles.escaleras-electricas.*')">
                            {{ __('Escaleras eléctricas') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('controles.elevadores.index')" :active="request()->routeIs('controles.elevadores.*')">
                            {{ __('Elevadores') }}
                        </x-responsive-nav-link>
                    </div>
                </div>
            @endhasanyrole
            @hasrole('Administrador')
                <x-responsive-nav-link :href="route('usuarios.index')" :active="request()->routeIs('usuarios.*')">
                    {{ __('Usuarios') }}
                </x-responsive-nav-link>
            @endhasrole
        </div>

        <!-- Responsive Settings Options -->
        <div class="border-t border-brand-green/10 pt-4 pb-1">
            <div class="px-4">
                <div class="font-heading text-base font-medium text-brand-green">{{ Auth::user()->name }}</div>
                <div class="text-sm font-medium text-gray-500">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
