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
            // grupos desplegables ("RR.HH.", "Recursos materiales", etc.),
            // que no son rutas navegables por sí mismas.
            $usuarioNav = auth()->user();
            // Grupos y enlaces según "Dashboard Tren Maya.pptx"
            // (config/navegacion.php), ya filtrados por rol.
            $gruposNav = app(\App\Support\MenuNavegacion::class)->grupos($usuarioNav, request());

            $navegacionBuscable = collect([
                $usuarioNav->hasRole('Estación') ? null : ['label' => __('Dashboard'), 'href' => route('dashboard')],
            ])->concat(
                $gruposNav->flatMap(fn (array $grupo) => collect($grupo['items'])
                    ->map(fn (array $item) => ['label' => $item['label'], 'href' => $item['href']]))
            )->push(
                $usuarioNav->hasRole('Administrador') ? ['label' => __('Usuarios'), 'href' => route('usuarios.index')] : null
            )->filter()->values();
        @endphp
        {{-- "open": estado del menú de hamburguesa, compartido con la barra
             (layouts/navigation.blade.php). Mientras está abierto se oculta
             el encabezado y el contenido: solo se ven las opciones del menú. --}}
        <div class="min-h-screen bg-brand-mist" x-data="{ open: false }"
             @resize.window="if (window.innerWidth >= 1280) open = false">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header x-show="! open" class="border-b border-brand-green/10 bg-white">
                    <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 sm:py-5 lg:px-8">
                        <div class="min-w-0 sm:border-s-4 sm:border-brand-mint sm:ps-4">
                            {{ $header }}
                        </div>

                        {{-- Buscador de escritorio: misma franja que el título.
                             Oculto por debajo de "sm": en celular vive dentro de
                             la hamburguesa. --}}
                        <x-buscador-menu id="buscar-nav" :items="$navegacionBuscable"
                                         class="hidden w-full shrink-0 sm:block sm:w-72" />
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main x-show="! open">
                {{ $slot }}
            </main>
        </div>

        @stack('scripts')
    </body>
</html>
