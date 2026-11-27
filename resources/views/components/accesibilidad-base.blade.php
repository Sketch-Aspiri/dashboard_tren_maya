{{-- Datos y marcado que usa resources/js/accesibilidad-base.js. MISMO ARCHIVO en Portal, Dashboard y Tickets.
     Va una vez por página, al final del <body>. --}}
@php
    $errores = [];
    foreach (isset($errors) ? $errors->getBags() : [] as $bolsa) {
        foreach ($bolsa->getMessages() as $campo => $mensajes) {
            $errores[] = ['campo' => (string) $campo, 'mensaje' => (string) ($mensajes[0] ?? '')];
        }
    }
@endphp

<div hidden data-a11y-base
     data-errores="{{ base64_encode((string) json_encode($errores)) }}"
     data-resumen-titulo="{{ __('Revisa lo siguiente:') }}"
     data-zona-nombre="{{ __('Contenido desplazable') }}"
     @auth data-sesion-minutos="{{ (int) config('session.lifetime') }}" data-sesion-url="{{ route('sesion.viva') }}" @endauth></div>
<p data-a11y-anuncio class="sr-only" role="status"></p>

@auth
    <dialog data-a11y-sesion aria-labelledby="a11y-sesion-titulo" aria-describedby="a11y-sesion-texto"
            class="m-auto w-[min(26rem,calc(100vw-2rem))] rounded-2xl border border-brand-green/15 bg-white p-5 text-left text-gray-900 shadow-xl backdrop:bg-black/60">
        <h2 id="a11y-sesion-titulo" class="font-heading text-lg font-bold text-brand-green">{{ __('Tu sesión está por terminar') }}</h2>
        <p id="a11y-sesion-texto" data-sesion-texto data-vencida="{{ __('La sesión terminó. Vuelve a entrar para continuar; lo que no se haya guardado se perderá.') }}"
           class="mt-2 text-sm text-gray-700">{{ __('Por inactividad se cerrará en unos minutos. Elige continuar para no perder lo que estás haciendo.') }}</p>
        <div class="mt-5 flex justify-end">
            <button type="button" data-sesion-seguir autofocus
                    class="inline-flex min-h-[44px] items-center rounded-lg bg-brand-green px-5 text-sm font-semibold text-white hover:bg-brand-green-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2">{{ __('Seguir conectado') }}</button>
            <a href="{{ route('login') }}" data-sesion-entrar hidden
               class="inline-flex min-h-[44px] items-center rounded-lg bg-brand-green px-5 text-sm font-semibold text-white hover:bg-brand-green-dark">{{ __('Volver a entrar') }}</a>
        </div>
    </dialog>
@endauth
