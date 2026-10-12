@props([
    'url',
    'titulo' => __('Arrastra aquí tus documentos'),
    'ayuda' => null,
    'campoExtra' => null,
])

{{-- Zona para arrastrar y soltar (o elegir) archivos. Sube cada lote por
     AJAX con barra de progreso (Alpine.data('zonaArrastre') en
     resources/js/app.js) y, si el servidor detectó una tabla en un solo
     archivo, abre su vista previa. Sin JavaScript, el <form> funciona igual
     con el botón "Subir". --}}
<form method="POST" action="{{ $url }}" enctype="multipart/form-data"
      x-data="zonaArrastre(@js($url))"
      @dragover.prevent="arrastrando = true"
      @dragleave.prevent="arrastrando = false"
      @drop.prevent="soltar($event)"
      @submit.prevent="subir($refs.selector.files)"
      {{ $attributes->merge(['class' => 'block']) }}>
    @csrf
    @if ($campoExtra)
        <input type="hidden" name="{{ $campoExtra['nombre'] }}" value="{{ $campoExtra['valor'] }}">
    @endif

    <label for="{{ $attributes->get('id', 'zona-arrastre') }}-input"
           :class="arrastrando ? 'border-brand-teal bg-brand-mist ring-2 ring-brand-teal/30' : 'border-brand-green/25 bg-white hover:border-brand-teal hover:bg-brand-mist/50'"
           class="flex min-h-[120px] cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-6 text-center transition">
        <svg class="h-9 w-9 text-brand-teal" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
        </svg>
        <span class="font-heading text-sm font-semibold text-brand-green">{{ $titulo }}</span>
        <span class="text-xs text-gray-600">
            {{ $ayuda ?? __('o haz clic para elegirlos. Excel, CSV, Word, PDF, PowerPoint o imágenes; hasta :mb MB cada uno.', ['mb' => intdiv((int) config('secciones.max_archivo_kb'), 1024)]) }}
        </span>
        <input id="{{ $attributes->get('id', 'zona-arrastre') }}-input" x-ref="selector" type="file" name="archivos[]" multiple class="sr-only"
               accept=".{{ implode(',.', config('secciones.extensiones')) }}"
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
        <div class="mt-1 h-2 overflow-hidden rounded-full bg-brand-mist">
            <div class="h-full rounded-full bg-brand-teal transition-all" :style="`width: ${progreso}%`"></div>
        </div>
    </div>

    <div x-show="errores.length > 0" x-cloak class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
        <template x-for="error in errores" :key="error">
            <p x-text="error"></p>
        </template>
    </div>

    <div x-show="resultados.length > 0 && ! subiendo" x-cloak class="mt-3 rounded-md border border-brand-teal/20 bg-brand-mist p-3 text-sm text-brand-green" role="status">
        <p class="font-semibold">{{ __('Archivos guardados:') }}</p>
        <ul class="mt-1 space-y-1">
            <template x-for="resultado in resultados" :key="resultado.id">
                <li class="flex flex-wrap items-center gap-2">
                    <span x-text="resultado.nombre"></span>
                    <template x-if="resultado.url_importacion">
                        <a :href="resultado.url_importacion" class="font-semibold text-brand-teal underline">{{ __('Revisar tabla detectada') }}</a>
                    </template>
                </li>
            </template>
        </ul>
        <button type="button" @click="window.location.reload()" class="mt-2 text-xs font-semibold text-brand-teal underline">{{ __('Actualizar la página') }}</button>
    </div>
</form>
