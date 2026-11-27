@props(['variante' => 'icono'])

{{-- Panel de preferencias de accesibilidad (resources/js/accesibilidad.js). MISMO ARCHIVO en Portal, Dashboard y
     Tickets. "icono": botón de la barra y de las páginas de acceso. "texto": botón con nombre, para los menús.
     El contenido del componente (selector de tema de cada sistema) aparece dentro del panel.
     <dialog> nativo: atrapa el foco, cierra con Escape y devuelve el foco al botón. --}}
@php
    $id = 'a11y-'.\Illuminate\Support\Str::random(6);
    $opciones = [
        'contraste' => [__('Contraste alto'), __('Texto y bordes más marcados.')],
        'movimiento' => [__('Reducir movimiento'), __('Detiene animaciones y fondos en movimiento.')],
        'subrayado' => [__('Subrayar enlaces'), __('Para distinguirlos sin depender del color.')],
    ];
@endphp

<div x-data="panelAccesibilidad" {{ $attributes }}>
    <button type="button" x-on:click="abrir" aria-haspopup="dialog"
            @if ($variante === 'icono') aria-label="{{ __('Accesibilidad') }}" title="{{ __('Accesibilidad') }}" @endif
            class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center gap-2 rounded-md border border-brand-green/15 px-2 text-sm font-semibold text-brand-green transition hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="4" r="1.5" />
            <path d="M4 8.5c2.5 1 5 1.500 8 1.5s5.500-.5 8-1.500M12 10v5m0 0-3.500 6M12 15l3.500 6" />
        </svg>
        @if ($variante !== 'icono')
            <span>{{ __('Accesibilidad') }}</span>
        @endif
    </button>

    <dialog x-ref="dialogo" x-on:change="cambiar" x-on:keydown.escape.stop="retener" aria-labelledby="{{ $id }}-titulo"
            data-guardado="{{ __('Preferencia guardada.') }}" data-restablecido="{{ __('Preferencias restablecidas.') }}"
            class="m-auto max-h-[90vh] w-[min(28rem,calc(100vw-2rem))] overflow-y-auto rounded-2xl border border-brand-green/15 bg-white p-0 text-left text-gray-900 shadow-xl backdrop:bg-black/60">
        <div class="space-y-5 p-5">
            <div class="flex items-start justify-between gap-3">
                <h2 id="{{ $id }}-titulo" class="font-heading text-lg font-bold text-brand-green">{{ __('Accesibilidad') }}</h2>
                <button type="button" x-on:click="cerrar" aria-label="{{ __('Cerrar') }}"
                        class="-m-2 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-gray-700 hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
            </div>

            <fieldset>
                <legend class="text-sm font-semibold text-gray-800">{{ __('Tamaño de letra') }}</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach (['100' => __('Normal'), '112' => __('Grande'), '125' => __('Más grande'), '150' => __('Máximo')] as $valor => $texto)
                        <label class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 rounded-lg border border-brand-green/15 px-3 text-sm text-gray-800">
                            <input type="radio" name="{{ $id }}-texto" value="{{ $valor }}" data-a11y="texto" class="text-brand-green focus:ring-brand-teal">
                            <span>{{ $texto }} <span class="text-gray-600">({{ $valor }} %)</span></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="space-y-1">
                <legend class="text-sm font-semibold text-gray-800">{{ __('Lectura') }}</legend>
                @foreach ($opciones as $nombre => [$texto, $ayuda])
                    <label class="flex min-h-[44px] cursor-pointer items-start gap-3 py-2">
                        <input type="checkbox" data-a11y="{{ $nombre }}" aria-describedby="{{ $id }}-{{ $nombre }}" class="mt-0.5 h-5 w-5 rounded text-brand-green focus:ring-brand-teal">
                        <span>
                            <span class="block text-sm font-medium text-gray-800">{{ $texto }}</span>
                            <span id="{{ $id }}-{{ $nombre }}" class="block text-sm text-gray-600">{{ $ayuda }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>

            @if (trim($slot) !== '')
                <div>{{ $slot }}</div>
            @endif

            <p role="status" x-text="aviso" class="min-h-[1.25rem] text-sm text-gray-600"></p>

            <div class="flex flex-wrap justify-between gap-2">
                <button type="button" x-on:click="restablecer"
                        class="inline-flex min-h-[44px] items-center rounded-lg border border-brand-green/15 px-4 text-sm font-semibold text-brand-green hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('Restablecer') }}</button>
                <button type="button" x-on:click="cerrar"
                        class="inline-flex min-h-[44px] items-center rounded-lg bg-brand-green px-5 text-sm font-semibold text-white hover:bg-brand-green-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2">{{ __('Listo') }}</button>
            </div>
        </div>
    </dialog>
</div>
