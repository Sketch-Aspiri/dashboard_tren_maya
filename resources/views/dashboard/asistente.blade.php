{{-- Asistente de preguntas (Claude API). Alpine.data('asistente') en
     resources/js/asistente.js: envía la pregunta, lee la respuesta en
     streaming (NDJSON) y la muestra conforme llega. --}}
<section x-data="asistente(@js(route('dashboard.asistente')))"
         class="rounded-xl border border-brand-teal/30 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6"
         aria-labelledby="titulo-asistente">
    <div class="flex items-center gap-2">
        <span class="text-lg" aria-hidden="true">✨</span>
        <h3 id="titulo-asistente" class="font-heading text-base font-semibold text-brand-green">{{ __('Pregunta rápida') }}</h3>
    </div>
    <p class="mt-1 text-xs text-gray-500">
        {{ __('Pregunta sobre el personal, la asistencia, las tablas de las secciones o los documentos. Las respuestas salen de los datos capturados en el sistema.') }}
    </p>

    <form @submit.prevent="preguntar()" class="mt-3 flex flex-col gap-2 sm:flex-row">
        <label for="pregunta-asistente" class="sr-only">{{ __('Tu pregunta') }}</label>
        <input id="pregunta-asistente" type="text" x-model="pregunta" x-ref="entrada" maxlength="1000" autocomplete="off"
               :disabled="pensando"
               placeholder="{{ __('Ej. ¿Cuánto personal activo tengo en Chetumal?') }}"
               class="min-h-[48px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal disabled:bg-gray-50">
        <button type="submit" :disabled="pensando || pregunta.trim().length < 2"
                class="inline-flex min-h-[48px] items-center justify-center gap-2 rounded-md bg-brand-green px-5 text-sm font-semibold text-white hover:bg-brand-green-dark focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2 disabled:opacity-50">
            <span x-show="! pensando">{{ __('Preguntar') }}</span>
            <span x-show="pensando" x-cloak>{{ __('Pensando…') }}</span>
        </button>
    </form>

    <div x-show="conversacion.length === 0" class="mt-3 flex flex-wrap gap-2">
        @foreach ([
            __('¿Cuánto personal activo hay en cada estación?'),
            __('¿Qué estaciones no han capturado asistencia hoy?'),
            __('¿Qué secciones tienen información capturada?'),
        ] as $ejemplo)
            <button type="button" @click="pregunta = @js($ejemplo); preguntar()"
                    class="min-h-[36px] rounded-full border border-brand-green/20 bg-brand-mist px-3 text-xs text-brand-green hover:border-brand-teal">
                {{ $ejemplo }}
            </button>
        @endforeach
    </div>

    <p class="sr-only" role="status" x-text="anuncio"></p>
    <p class="mt-2 text-xs text-gray-600">
        {{ __('La IA puede equivocarse: verifica las cifras importantes en la sección indicada. Para responder, se envían a Anthropic (Claude) solo los resultados de las consultas, sin datos personales sensibles.') }}
    </p>

    <div class="mt-4 space-y-4" aria-live="off" :aria-busy="pensando.toString()">
        <template x-for="(turno, indice) in conversacion" :key="indice">
            <div class="space-y-2">
                <p class="text-sm font-semibold text-gray-900" x-text="turno.pregunta"></p>
                <div class="rounded-lg bg-brand-mist/60 p-3 text-sm text-gray-800">
                    <p x-show="turno.estado && ! turno.respuesta" class="italic text-gray-500" x-text="turno.estado"></p>
                    <div class="respuesta-asistente space-y-2" x-html="formatear(turno.respuesta)"></div>
                    <p x-show="turno.error" class="mt-2 text-red-700" x-text="turno.error"></p>
                    <div x-show="turno.fuentes.length > 0" class="mt-2 flex flex-wrap items-center gap-2 border-t border-brand-green/10 pt-2 text-xs">
                        <span class="text-gray-500">{{ __('Fuentes:') }}</span>
                        <template x-for="fuente in turno.fuentes" :key="fuente.url">
                            <a :href="fuente.url" class="rounded-full bg-white px-2 py-0.5 font-medium text-brand-teal hover:underline" x-text="fuente.titulo"></a>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <button type="button" x-show="conversacion.length > 0 && ! pensando" x-cloak @click="conversacion = []; $refs.entrada.focus()"
            class="mt-3 text-xs font-medium text-gray-500 hover:text-brand-green">{{ __('Nueva conversación') }}</button>
</section>
