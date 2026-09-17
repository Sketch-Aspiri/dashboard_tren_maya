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
        <div class="min-h-screen flex flex-col items-center justify-center bg-brand-mist px-4 py-10 sm:py-16">

            <a href="/" class="mb-8 inline-flex" aria-label="{{ config('app.name', 'Dashboard - Zona Oriente') }} — inicio">
                <img src="{{ asset('logo.png') }}" alt="Tren Maya" class="h-16 w-auto sm:h-20">
            </a>

            <main class="w-full sm:max-w-md">
                <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white px-6 py-8 shadow-lg shadow-brand-green/5 sm:px-8">
                    {{ $slot }}
                </div>
            </main>

            <p class="mt-8 text-center text-xs text-gray-500">
                {{ __('Dashboard - Zona Oriente') }} &middot; {{ __('Acceso interno, uso exclusivo vía VPN') }}
            </p>
        </div>
    </body>
</html>
