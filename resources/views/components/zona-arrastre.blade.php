@props([
    'url',
    'titulo' => __('Arrastra aquí tus documentos'),
    'ayuda' => null,
    'campoExtra' => null,
    'compacta' => false,
    'accept' => null,
])

@php
    $idInput = $attributes->get('id', 'zona-arrastre').'-input';
    $aceptados = $accept ?? '.'.implode(',.', config('secciones.extensiones'));
@endphp

{{-- Zona para arrastrar y soltar (o elegir) archivos. Sube cada lote por
     AJAX con barra de progreso (Alpine.data('zonaArrastre') en
     resources/js/secciones.js) y, si el servidor detectó una tabla en un
     solo archivo, abre su vista previa. "compacta": se muestra como botón
     y se despliega al pulsarlo (para no empujar la tabla en pantallas de
     consulta). Sin JavaScript, el <form> funciona con el botón "Subir". --}}
<div x-data="{ abierta: @js(! $compacta) }">
    @if ($compacta)
        <button type="button" x-show="! abierta" @click="abierta = true; $nextTick(() => $refs.zona?.focus())"
                class="inline-flex min-h-[44px] items-center gap-2 rounded-md border border-brand-green/20 bg-white px-4 text-sm font-medium text-brand-green hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
            </svg>
            {{ __('Cargar documento') }}
        </button>
    @endif

    <form method="POST" action="{{ $url }}" enctype="multipart/form-data"
          x-show="abierta" @if ($compacta) x-cloak @endif
          x-data="zonaArrastre(@js($url))"
          @dragover.prevent="arrastrando = true"
          @dragleave.prevent="if (! $el.contains($event.relatedTarget)) arrastrando = false"
          @drop.prevent="soltar($event)"
          @submit.prevent="subir($refs.selector.files)"
          {{ $attributes->except('id')->merge(['class' => 'block']) }}>
        @csrf
        @if ($campoExtra)
            <input type="hidden" name="{{ $campoExtra['nombre'] }}" value="{{ $campoExtra['valor'] }}">
        @endif

        <label for="{{ $idInput }}" x-ref="zona" tabindex="-1"
               :class="arrastrando ? 'border-brand-teal bg-brand-mist ring-2 ring-brand-teal/30' : 'border-brand-green/25 bg-white hover:border-brand-teal hover:bg-brand-mist/50'"
               class="flex min-h-[120px] cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-6 text-center transition focus-within:ring-2 focus-within:ring-brand-teal">
            <svg class="h-9 w-9 text-brand-teal" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
            </svg>
            <span class="hidden font-heading text-sm font-semibold text-brand-green sm:inline">{{ $titulo }}</span>
            <span class="font-heading text-sm font-semibold text-brand-green sm:hidden">{{ __('Toca para elegir un archivo') }}</span>
            <span class="text-xs text-gray-600">
                {{ $ayuda ?? __('o haz clic para elegirlos. Excel, CSV, Word, PDF, PowerPoint o imágenes; hasta :mb MB cada uno.', ['mb' => intdiv((int) config('secciones.max_archivo_kb'), 1024)]) }}
            </span>
            <input id="{{ $idInput }}" x-ref="selector" type="file" name="archivos[]" multiple class="sr-only"
                   accept="{{ $aceptados }}"
                   @change="subir($event.target.files)">
        </label>

        <noscript>
            <button type="submit" class="mt-2 rounded-md bg-brand-green px-4 py-2 text-sm font-semibold text-white">{{ __('Subir') }}</button>
        </noscript>

        <div x-show="subiendo" x-cloak class="mt-3" role="status" aria-live="polite">
            <div class="flex items-center justify-between text-xs text-gray-600">
                <span x-text="mensaje"></span>
                <span x-text="progreso + '%'"></span>
            </div>
            <div class="mt-1 h-2 overflow-hidden rounded-full bg-brand-mist" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="progreso">
                <div class="h-full rounded-full bg-brand-teal transition-all" :style="`width: ${progreso}%`"></div>
            </div>
        </div>

        <div x-show="errores.length > 0" x-cloak class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
            <template x-for="error in errores" :key="error">
                <p x-text="error"></p>
            </template>
        </div>

        <div x-show="resultados.length > 0 && ! subiendo" x-cloak class="mt-3 rounded-md border border-brand-teal/20 bg-brand-mist p-3 text-sm text-brand-green" role="status">
            <p class="font-semibold">{{ __('Archivos guardados en Documentos:') }}</p>
            <ul class="mt-1 space-y-1">
                <template x-for="resultado in resultados" :key="resultado.id">
                    <li class="flex flex-wrap items-center gap-2">
                        <span x-text="resultado.nombre"></span>
                        <template x-if="resultado.url_importacion">
                            <a :href="resultado.url_importacion" class="font-semibold text-brand-teal underline">{{ __('Tabla detectada: revisar') }}</a>
                        </template>
                        <template x-if="! resultado.url_importacion">
                            <span class="text-xs text-gray-600">{{ __('(no se detectó una tabla)') }}</span>
                        </template>
                    </li>
                </template>
            </ul>
            <button type="button" @click="window.location.reload()" class="mt-2 inline-flex min-h-[44px] items-center text-sm font-semibold text-brand-teal underline">{{ __('Ver los cambios') }}</button>
        </div>
    </form>
</div>
