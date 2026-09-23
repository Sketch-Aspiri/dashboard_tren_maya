<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Dashboard - Zona Oriente') }}</title>

        <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600|poppins:600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        @php
            // Lista de destinos del menú para el buscador de la barra de
            // navegación (resources/js/app.js → Alpine.data('navBuscador', ...)).
            // Se calcula aquí (y no en layouts/navigation.blade.php) porque el
            // buscador de escritorio vive en esta misma fila que el título de
            // página (slot "header", justo abajo) — así "Buscar en el menú…"
            // y el título se leen como una sola franja de encabezado en vez
            // de dos tiras apiladas. Blade comparte el scope de esta vista
            // hacia @include, así que layouts/navigation.blade.php (que solo
            // necesita la misma lista para su copia del buscador en el menú
            // de hamburguesa) recibe la variable sin tener que recalcularla.
            //
            // Reutiliza exactamente las mismas condiciones de rol que las
            // guardan los enlaces del menú en layouts/navigation.blade.php
            // (@unlessrole/@hasanyrole/@hasrole) — nunca una lista fija —
            // para no ofrecer un enlace que el usuario actual no vería en el
            // menú. Solo incluye destinos (rutas), no las etiquetas de los
            // grupos desplegables ("Agenda Zona Oriente", "Asistencia", etc.),
            // que no son rutas navegables por sí mismas.
            $usuarioNav = auth()->user();
            $puedeVerComunes = ! $usuarioNav->hasRole('Estación');
            $puedeVerAsistenciaZona = $usuarioNav->hasAnyRole(['Jefe de Zona', 'Administrador']);
            $puedeVerCapturaAsistencia = $usuarioNav->hasAnyRole(['Estación', 'Administrador']);
            $puedeVerEstadisticasYControles = $usuarioNav->hasAnyRole(['Jefe de Zona', 'Administrador', 'Estación']);
            $puedeVerUsuarios = $usuarioNav->hasRole('Administrador');

            $navegacionBuscable = collect([
                $puedeVerComunes ? ['label' => __('Dashboard'), 'href' => route('dashboard')] : null,
                $puedeVerComunes ? ['label' => __('Personal'), 'href' => route('agenda.personal.index')] : null,
                $puedeVerComunes ? ['label' => __('Rol de vacaciones'), 'href' => route('agenda.vacaciones.index')] : null,
                $puedeVerAsistenciaZona ? ['label' => __('Asistencia Zona Oriente'), 'href' => route('asistencia.zona.index')] : null,
                $puedeVerCapturaAsistencia ? ['label' => __('Captura de asistencia'), 'href' => route('asistencia.captura.index')] : null,
                $puedeVerEstadisticasYControles ? ['label' => __('Flujo de pasajeros'), 'href' => route('estadisticas.index')] : null,
                $puedeVerEstadisticasYControles ? ['label' => __('Gasto energético'), 'href' => route('estadisticas.gasto-energetico.index')] : null,
                $puedeVerEstadisticasYControles ? ['label' => __('Escaleras eléctricas'), 'href' => route('controles.escaleras-electricas.index')] : null,
                $puedeVerEstadisticasYControles ? ['label' => __('Elevadores'), 'href' => route('controles.elevadores.index')] : null,
                $puedeVerEstadisticasYControles ? ['label' => __('Estatus de vías y andenes'), 'href' => route('controles.estatus-vias-andenes.index')] : null,
                $puedeVerUsuarios ? ['label' => __('Usuarios'), 'href' => route('usuarios.index')] : null,
            ])->filter()->values();
        @endphp
        <div class="min-h-screen bg-brand-mist">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="border-b border-brand-green/10 bg-white">
                    <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-6 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                        {{ $header }}

                        {{-- Buscador de escritorio: misma fila que el título de
                             página (arriba), alineado a la derecha, para que
                             el encabezado se lea como una sola franja
                             cohesiva en vez de dos tiras delgadas apiladas
                             (esa era justo la queja de diseño). Oculto por
                             debajo de "sm" — en celular ya está disponible
                             dentro del menú de hamburguesa (ver
                             layouts/navigation.blade.php).

                             x-data va entre comillas SIMPLES a propósito:
                             @json() genera JSON, que usa comillas DOBLES para
                             cada string — no es algo que @json() pueda
                             evitar (sus flags JSON_HEX_* solo escapan
                             comillas DENTRO de un valor, nunca las que
                             delimitan cada string). Con x-data entre comillas
                             dobles, el navegador corta el atributo en la
                             primera comilla del JSON — bug real, reproducido
                             con la consola del navegador (ReferenceError:
                             query/abierto/resultados is not defined). --}}
                        <div class="relative hidden w-full shrink-0 sm:block sm:w-72"
                             x-data='navBuscador(@json($navegacionBuscable))' @click.outside="abierto = false">
                            <label for="buscar-nav" class="sr-only">{{ __('Buscar en el menú') }}</label>
                            <x-text-input id="buscar-nav" type="search"
                                          class="block w-full text-sm"
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
                                           class="block min-h-[44px] w-full items-center px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-brand-mist hover:text-brand-green focus:outline-none focus:bg-brand-mist transition duration-150 ease-in-out"></a>
                                    </li>
                                </template>
                            </ul>
                            <p x-show="abierto && query.trim() !== '' && resultados.length === 0" x-cloak
                               class="absolute z-50 mt-1 w-full rounded-md border border-brand-green/10 bg-white px-4 py-2 text-sm text-gray-500 shadow-lg">
                                {{ __('Sin resultados.') }}
                            </p>
                        </div>
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>

        @stack('scripts')
    </body>
</html>
