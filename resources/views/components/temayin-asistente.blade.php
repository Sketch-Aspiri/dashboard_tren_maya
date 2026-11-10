{{-- Temayin, asistente de preguntas frecuentes (mismo componente que el
     sistema de Tickets). Las preguntas ya vienen filtradas por rol desde
     App\Support\Temayin y viajan en data-faqs (JSON escapado); la lógica
     está en resources/js/temayin.js (Alpine.data('temayin')). --}}
@php
    $usuarioTemayin = auth()->user();
    $temayin = app(\App\Support\Temayin::class);
    $faqsTemayin = $usuarioTemayin ? $temayin->preguntas($usuarioTemayin) : [];
    // Sube el botón donde hay una barra de acciones fija abajo.
    $desfaseTemayin = request()->routeIs('insumos.show', 'insumos.estacion', 'insumos.cotizaciones.create', 'insumos.cotizaciones.edit', 'asistencia.captura.*')
        ? 'bottom-28 sm:bottom-24' : 'bottom-4';
@endphp

@if ($usuarioTemayin && $faqsTemayin !== [])
    <div x-data="temayin" data-faqs="{{ json_encode($faqsTemayin, JSON_UNESCAPED_UNICODE) }}" class="fixed right-4 z-50 {{ $desfaseTemayin }}">
        <section x-ref="panel" x-show="open" x-cloak tabindex="-1" id="temayin-panel" role="dialog" aria-label="{{ __('Temayin') }}"
                 x-on:keydown.escape.window="close"
                 class="mb-3 flex max-h-[70vh] w-[calc(100vw-2rem)] max-w-sm flex-col overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-xl focus:outline-none">
            <header class="flex items-center gap-3 bg-brand-green px-4 py-3 text-white">
                <img src="{{ asset('temayin-soporte.jpg') }}" alt="" width="40" height="40" class="h-10 w-10 shrink-0 rounded-full bg-white object-cover">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">{{ __('Temayin') }}</p>
                    <p class="text-xs text-white/80">{{ __('Asistente virtual') }}</p>
                </div>
                <button type="button" x-on:click="close" aria-label="{{ __('Cerrar asistente') }}" class="inline-flex h-9 w-9 items-center justify-center rounded-md hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </header>

            <div class="space-y-3 overflow-y-auto p-4 text-sm" aria-live="polite">
                <p class="rounded-lg bg-brand-mist px-3 py-2 text-gray-800">{{ __('¡Hola! Soy Temayin. Elige una pregunta y te explico cómo funciona el sistema.') }}</p>

                <div x-show="hasNoActive">
                    <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Preguntas frecuentes') }}</h2>
                    <ul class="space-y-2">
                        @foreach ($faqsTemayin as $id => $faq)
                            <li>
                                <button type="button" data-id="{{ $id }}" x-on:click="ask" class="w-full rounded-lg border border-brand-teal/30 px-3 py-2 text-left text-brand-green hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ $faq['q'] }}</button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div x-show="hasActive" x-cloak class="space-y-3">
                    <p class="ml-auto max-w-[90%] rounded-lg bg-brand-green px-3 py-2 text-white" x-text="activeQuestion"></p>
                    <p class="max-w-[95%] whitespace-pre-line rounded-lg bg-brand-mist px-3 py-2 text-gray-800" x-text="activeAnswer"></p>
                    <a x-show="hasLink" x-bind:href="activeUrl" class="inline-flex min-h-[44px] items-center gap-1 font-semibold text-brand-teal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                        <span x-text="activeLink"></span> <span aria-hidden="true">→</span>
                    </a>
                    <button type="button" x-on:click="back" class="flex items-center gap-1 rounded-md text-sm font-semibold text-brand-teal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14L4 9l5-5M4 9h10a6 6 0 010 12h-3" /></svg>
                        {{ __('Ver otras preguntas') }}
                    </button>
                </div>
            </div>
        </section>

        {{-- Burbuja flotante: recuerda reportar fallas a Soporte del sistema (en Tickets). Se cierra y no vuelve en la sesión. --}}
        <div x-show="showBubble" x-cloak class="mb-2 flex justify-end">
            <div role="note" class="relative max-w-[16rem] rounded-xl border border-brand-teal/30 bg-white py-2 pe-8 ps-3 text-sm text-gray-800 shadow-lg">
                <p>{{ __('¿Algún problema con el sistema? Recuerda crear un ticket a Soporte del sistema.') }}</p>
                <a href="{{ $temayin->urlSoporte() }}" target="_blank" rel="noopener" class="mt-1 inline-flex min-h-[44px] items-center font-semibold text-brand-teal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('Crear ticket a Soporte') }}</a>
                <button type="button" x-on:click="dismissBubble" aria-label="{{ __('Cerrar recordatorio') }}" class="absolute end-1 top-1 inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
                <span class="absolute -bottom-1.5 end-6 h-3 w-3 rotate-45 border-b border-e border-brand-teal/30 bg-white" aria-hidden="true"></span>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="button" x-ref="toggle" x-on:click="toggle" x-bind:aria-expanded="toggleExpanded" aria-controls="temayin-panel" aria-label="{{ __('Abrir asistente Temayin') }}"
                    class="h-14 w-14 overflow-hidden rounded-full border-2 border-white bg-white shadow-lg transition hover:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2 motion-reduce:transition-none">
                <img src="{{ asset('temayin-soporte.jpg') }}" alt="{{ __('Temayin, asistente virtual') }}" width="56" height="56" class="h-full w-full object-cover">
            </button>
        </div>
    </div>
@endif
