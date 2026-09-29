{{-- $navegacionBuscable (destinos del menú) y $gruposNav se calculan en
     layouts/app.blade.php; Blade comparte ese scope con este @include.
     Cada grupo trae sus items con 'activo', 'href' y 'pendiente' (ver
     App\Support\MenuNavegacion). --}}
<nav class="relative z-40 bg-white shadow-sm" aria-label="{{ __('Navegación principal') }}">
    {{-- Nivel 1: identidad (logo + nombre del sistema) y cuenta. Es la única
         fila visible por debajo de xl; ahí la hamburguesa reemplaza al nivel 2. --}}
    <div class="border-b border-brand-green/10">
        <div class="mx-auto flex h-16 max-w-screen-2xl items-center justify-between gap-3 px-4 sm:h-[72px] sm:px-6 lg:px-8">
            <a href="{{ route('dashboard') }}"
               class="flex min-w-0 items-center gap-3 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2"
               aria-label="{{ config('app.name', 'Dashboard - Zona Oriente') }} — {{ __('inicio') }}">
                <x-brand-logo class="h-11 sm:h-14" />
                <span class="h-8 w-px shrink-0 bg-brand-green/20 sm:h-10" aria-hidden="true"></span>
                <span class="min-w-0 whitespace-nowrap font-heading leading-tight text-brand-green">
                    <span class="block text-sm font-semibold sm:text-base">{{ __('Dashboard') }}</span>
                    <span class="block text-xs font-normal text-gray-600 sm:text-sm">{{ __('Zona Oriente') }}</span>
                </span>
            </a>

            <!-- Settings Dropdown -->
            <div class="hidden xl:flex xl:items-center">
                <x-dropdown align="right" width="w-56">
                    <x-slot name="trigger">
                        <button type="button" class="inline-flex min-h-[44px] items-center gap-2 rounded-md border border-brand-green/15 px-3 py-2 text-sm font-medium text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-green text-xs font-semibold uppercase text-white" aria-hidden="true">{{ mb_substr(Auth::user()->name, 0, 1) }}</span>
                            <span class="max-w-[14rem] truncate">{{ Auth::user()->name }}</span>
                            <svg class="h-4 w-4 fill-current transition-transform" :class="{ 'rotate-180': open }" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="border-b border-gray-100 px-4 py-2">
                            <p class="truncate text-sm font-medium text-brand-green">{{ Auth::user()->name }}</p>
                            <p class="truncate text-xs text-gray-600">{{ Auth::user()->email }}</p>
                        </div>
                        @hasrole('Administrador')
                            <x-dropdown-link :href="route('usuarios.index')">
                                {{ __('Usuarios') }}
                            </x-dropdown-link>
                        @endhasrole
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
            <div class="-me-2 flex items-center xl:hidden">
                <button type="button" @click="open = ! open"
                        :aria-expanded="open.toString()"
                        aria-label="{{ __('Abrir menú de navegación') }}"
                        class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-md p-2 text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                    <svg class="h-7 w-7" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- Nivel 2 (xl+): barra de módulos. Cada etiqueta lleva whitespace-nowrap
         y los elementos shrink-0: las 7 entradas miden ~950px, así que caben
         en los 1216px útiles de 1280 sin compresión ni saltos de línea. Sin
         overflow-hidden aquí: los desplegables salen hacia abajo. --}}
    <div class="hidden bg-brand-green xl:block">
        <div class="mx-auto flex max-w-screen-2xl items-center gap-1 px-4 sm:px-6 lg:px-8">
            @unlessrole('Estación')
                <a href="{{ route('dashboard') }}"
                   @if (request()->routeIs('dashboard')) aria-current="page" @endif
                   class="{{ request()->routeIs('dashboard') ? 'border-brand-mint bg-white/10 font-semibold text-white' : 'border-transparent font-medium text-white/85 hover:bg-white/10 hover:text-white' }} inline-flex h-12 shrink-0 items-center whitespace-nowrap border-b-4 px-4 text-sm transition duration-150 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white">
                    {{ __('Dashboard') }}
                </a>
            @endunlessrole
            {{-- Grupos ($gruposNav, según "Dashboard Tren Maya.pptx"), ya
                 filtrados por rol. Dentro de cada uno: primero los módulos
                 que ya existen y, separados, los conceptos pendientes de
                 información. --}}
            @foreach ($gruposNav as $grupo)
                @php
                    $existentes = collect($grupo['items'])->reject(fn ($item) => $item['pendiente']);
                    $pendientes = collect($grupo['items'])->filter(fn ($item) => $item['pendiente']);
                @endphp
                <x-dropdown align="left" width="w-80" class="flex shrink-0" trigger-classes="flex" content-classes="max-h-[70vh] overflow-y-auto bg-white py-1">
                    <x-slot name="trigger">
                        <button type="button" aria-haspopup="true" :aria-expanded="open.toString()"
                                @if ($grupo['activo']) aria-current="true" @endif
                                :class="{ 'bg-white/10': open }"
                                class="{{ $grupo['activo'] ? 'border-brand-mint font-semibold text-white' : 'border-transparent font-medium text-white/85 hover:text-white' }} inline-flex h-12 items-center gap-1.5 whitespace-nowrap border-b-4 px-4 text-sm transition duration-150 ease-in-out hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white">
                            {{ __($grupo['label']) }}
                            <svg class="h-4 w-4 fill-current transition-transform" :class="{ 'rotate-180': open }" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        @foreach ($existentes as $item)
                            <x-dropdown-link :href="$item['href']" :aria-current="$item['activo'] ? 'page' : null"
                                             class="{{ $item['activo'] ? 'border-s-4 border-brand-teal bg-brand-mist font-semibold !text-brand-green' : 'border-s-4 border-transparent font-medium' }}">
                                {{ __($item['label']) }}
                            </x-dropdown-link>
                        @endforeach
                        @if ($pendientes->isNotEmpty())
                            <p class="{{ $existentes->isNotEmpty() ? 'mt-1 border-t border-gray-100 pt-3' : 'pt-2' }} px-4 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                {{ __('Pendiente de información') }}
                            </p>
                            @foreach ($pendientes as $item)
                                <x-dropdown-link :href="$item['href']" :aria-current="$item['activo'] ? 'page' : null"
                                                 class="{{ $item['activo'] ? 'border-s-4 border-brand-teal bg-brand-mist font-semibold !text-brand-green' : 'border-s-4 border-transparent text-gray-600' }}">
                                    {{ __($item['label']) }}
                                </x-dropdown-link>
                            @endforeach
                        @endif
                    </x-slot>
                </x-dropdown>
            @endforeach
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden border-b border-brand-green/10 xl:hidden">
        <div class="px-4 pt-4">
            <x-buscador-menu id="buscar-nav-movil" :items="$navegacionBuscable" />
        </div>
        <div class="space-y-1 pb-3 pt-3">
            @unlessrole('Estación')
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    {{ __('Dashboard') }}
                </x-responsive-nav-link>
            @endunlessrole
            {{-- Mismos grupos que la barra de escritorio, como acordeón Alpine
                 con su propio x-data ("abierto"), distinto del "open" de la
                 hamburguesa. El grupo activo arranca abierto. --}}
            @foreach ($gruposNav as $grupo)
                @php
                    $existentes = collect($grupo['items'])->reject(fn ($item) => $item['pendiente']);
                    $pendientes = collect($grupo['items'])->filter(fn ($item) => $item['pendiente']);
                @endphp
                <div x-data="{ abierto: {{ $grupo['activo'] ? 'true' : 'false' }} }">
                    <button type="button" @click="abierto = ! abierto"
                            :aria-expanded="abierto.toString()"
                            class="{{ $grupo['activo']
                                ? 'border-brand-teal bg-brand-mist font-semibold text-brand-green'
                                : 'border-transparent font-medium text-gray-700 hover:border-brand-mint hover:bg-brand-mist hover:text-brand-green' }} flex min-h-[48px] w-full items-center justify-between border-l-4 py-2 pe-4 ps-3 text-start text-base transition duration-150 ease-in-out focus:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-inset focus:ring-brand-teal">
                        <span>{{ __($grupo['label']) }}</span>
                        <svg class="h-5 w-5 fill-current transition-transform" :class="{ 'rotate-180': abierto }"
                             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div x-show="abierto" x-cloak class="ms-4 border-s border-brand-green/15 py-1 ps-2">
                        @foreach ($existentes as $item)
                            <x-responsive-nav-link :href="$item['href']" :active="$item['activo']" class="text-sm">
                                {{ __($item['label']) }}
                            </x-responsive-nav-link>
                        @endforeach
                        @if ($pendientes->isNotEmpty())
                            <p class="px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                {{ __('Pendiente de información') }}
                            </p>
                            @foreach ($pendientes as $item)
                                <x-responsive-nav-link :href="$item['href']" :active="$item['activo']" class="text-sm">
                                    {{ __($item['label']) }}
                                </x-responsive-nav-link>
                            @endforeach
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Responsive Settings Options -->
        <div class="border-t border-brand-green/10 bg-brand-mist pb-2 pt-4">
            <div class="px-4">
                <div class="font-heading text-base font-medium text-brand-green">{{ Auth::user()->name }}</div>
                <div class="text-sm font-medium text-gray-600">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                @hasrole('Administrador')
                    <x-responsive-nav-link :href="route('usuarios.index')" :active="request()->routeIs('usuarios.*')">
                        {{ __('Usuarios') }}
                    </x-responsive-nav-link>
                @endhasrole
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
