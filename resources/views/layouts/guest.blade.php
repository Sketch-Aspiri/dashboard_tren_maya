<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

@php
    // Título propio de cada pantalla: la ranura "title" o el primer <h1> de la cabecera o del contenido.
    $tituloPagina = trim((string) ($title ?? ''));
    if ($tituloPagina === '' && preg_match('~<h1\b[^>]*>(.*?)</h1>~s', (string) ($header ?? '').(string) ($slot ?? ''), $coincidencia)) {
        $tituloPagina = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($coincidencia[1]), ENT_QUOTES | ENT_HTML5)));
    }
@endphp
        <title>{{ $tituloPagina !== '' ? $tituloPagina.' · ' : '' }}{{ config('app.name', 'Dashboard - Zona Oriente') }}</title>

        <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">


        @include('layouts.partials.tema')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <x-saltar-contenido />
        <div class="min-h-screen flex flex-col items-center justify-center bg-brand-mist px-4 py-10 sm:py-16">

            <x-panel-accesibilidad class="mb-4 self-end" />

            <a href="/" class="mb-8 inline-flex" aria-label="{{ config('app.name', 'Dashboard - Zona Oriente') }} — inicio">
                <img src="{{ asset('logo.png') }}" alt="Tren Maya" class="logo-placa h-16 w-auto sm:h-20">
            </a>

            <main id="contenido" tabindex="-1" class="w-full sm:max-w-md">
                <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white px-6 py-8 shadow-lg shadow-brand-green/5 sm:px-8">
                    {{ $slot }}
                </div>
            </main>

            <p class="mt-8 text-center text-xs text-gray-500">
                {{ __('Dashboard - Zona Oriente') }} &middot; {{ __('Acceso interno, uso exclusivo vía VPN') }}
            </p>
        </div>

        <x-accesibilidad-base />
        <x-pantalla-carga />
    </body>
</html>
