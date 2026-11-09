{{-- Paso 2. Dos preguntas en lenguaje claro; la subsección E.a/E.b/E.c se decide sola. --}}
@php
    $claseCampo = 'mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal';
    $claseEtiqueta = 'block text-sm font-medium text-gray-700';
@endphp

<div class="space-y-4">
    <datalist id="lista-coordinaciones"><template x-for="valor in sugerencias.coordinaciones" :key="valor"><option :value="valor"></option></template></datalist>
    <datalist id="lista-ubicaciones"><template x-for="valor in sugerencias.ubicaciones" :key="valor"><option :value="valor"></option></template></datalist>
    <datalist id="lista-origenes"><template x-for="valor in sugerencias.origenes" :key="valor"><option :value="valor"></option></template></datalist>
    <datalist id="lista-visitantes"><template x-for="v in sugerencias.visitantes" :key="v.nombre"><option :value="v.nombre"></option></template></datalist>

    {{-- Lo del último día capturado que sigue vigente: un clic en vez de volver a teclearlo. --}}
    <div x-show="puedeGestionar && (fueraAnterioresDisponibles.length + visitantesAnterioresDisponibles.length) > 0" x-cloak
         class="rounded-xl border border-brand-teal/30 bg-brand-mist p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium text-brand-green-dark">{{ __('Del último día capturado, siguen vigentes:') }}</p>
            <x-primary-button type="button" @click="traerTodos()" x-bind:disabled="trayendo" class="disabled:opacity-60">{{ __('Traer todos') }}</x-primary-button>
        </div>
        <ul class="mt-2 space-y-1 text-sm">
            <template x-for="c in fueraAnterioresDisponibles" :key="`f${c.id}`">
                <li class="flex flex-wrap items-center justify-between gap-2">
                    <span x-text="`${persona(c.empleado_id)?.nombre ?? ''} → ${c.coordinacion_destino}`"></span>
                    <button type="button" @click="traerFuera(c)" class="min-h-[36px] text-xs font-semibold text-brand-green underline">{{ __('Traer') }}</button>
                </li>
            </template>
            <template x-for="v in visitantesAnterioresDisponibles" :key="`v${v.id}`">
                <li class="flex flex-wrap items-center justify-between gap-2">
                    <span x-text="`${v.nombre} (de ${v.direccion_origen})`"></span>
                    <button type="button" @click="traerVisitante(v)" class="min-h-[36px] text-xs font-semibold text-brand-green underline">{{ __('Traer') }}</button>
                </li>
            </template>
        </ul>
    </div>

    {{-- Personal propio que salió --}}
    <section class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('¿Alguien de tu personal salió comisionado a otra área?') }}</h3>
                <p class="mt-0.5 text-sm text-gray-600">{{ __('Se imprime en la sección E: E.b si es permanente y E.c si es eventual, según su plaza.') }}</p>
            </div>
            <x-secondary-button type="button" x-show="puedeGestionar && !formFuera" @click="abrirFuera()">{{ __('Agregar persona') }}</x-secondary-button>
        </div>

        <p x-show="fuera.length === 0 && !formFuera" class="mt-3 text-sm text-gray-500">{{ __('Nadie comisionado a otra área en esta fecha.') }}</p>

        <ul class="mt-3 divide-y divide-gray-100" x-show="fuera.length > 0">
            <template x-for="c in fuera" :key="c.id">
                <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">
                            <span x-text="persona(c.empleado_id)?.nombre ?? '{{ __('Persona fuera de la lista') }}'"></span>
                            <span class="ml-1 rounded bg-brand-mist px-1.5 py-0.5 text-xs font-semibold text-brand-green" x-text="seccionDe(persona(c.empleado_id))"></span>
                        </p>
                        <p class="text-xs text-gray-600" x-text="lineasDeFuera(c).join(' · ')"></p>
                        <p class="text-xs text-gray-500" x-text="c.ubicacion_destino"></p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3 text-sm" x-show="puedeGestionar">
                        <button type="button" @click="abrirFuera(c)" class="min-h-[40px] font-medium text-brand-green underline">{{ __('Editar') }}</button>
                        <button type="button" x-show="confirmando !== `f${c.id}`" @click="confirmando = `f${c.id}`" class="min-h-[40px] font-medium text-red-700 underline">{{ __('Quitar') }}</button>
                        <button type="button" x-show="confirmando === `f${c.id}`" @click="quitarFuera(c)" class="min-h-[40px] rounded-md bg-red-700 px-3 font-medium text-white">{{ __('¿Seguro? Quitar') }}</button>
                    </div>
                </li>
            </template>
        </ul>

        <template x-if="formFuera">
            <form @submit.prevent="guardarFuera()" class="mt-3 grid gap-3 border-t border-brand-green/10 pt-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $claseEtiqueta }}" for="f-persona">{{ __('¿Quién?') }}</label>
                    <select id="f-persona" x-model="formFuera.empleado_id" required class="{{ $claseCampo }}">
                        <option value="">{{ __('Elige a la persona') }}</option>
                        <template x-for="p in personasDisponibles" :key="p.id">
                            <option :value="p.id" :selected="Number(formFuera.empleado_id) === p.id" x-text="p.nombre"></option>
                        </template>
                    </select>
                    <p class="mt-1 text-xs text-red-600" x-text="formFuera.errores.empleado_id?.[0]"></p>
                </div>
                <div>
                    <label class="{{ $claseEtiqueta }}" for="f-coordinacion">{{ __('¿A qué coordinación o área?') }}</label>
                    <input id="f-coordinacion" list="lista-coordinaciones" maxlength="255" required x-model="formFuera.coordinacion_destino" placeholder="{{ __('Ej. CGOFP') }}" class="{{ $claseCampo }}">
                    <p class="mt-1 text-xs text-red-600" x-text="formFuera.errores.coordinacion_destino?.[0]"></p>
                </div>
                <div>
                    <label class="{{ $claseEtiqueta }}" for="f-ubicacion">{{ __('¿En qué lugar está?') }}</label>
                    <input id="f-ubicacion" list="lista-ubicaciones" maxlength="255" required x-model="formFuera.ubicacion_destino" placeholder="{{ __('Se imprime en Ubicación actual') }}" class="{{ $claseCampo }}">
                    <p class="mt-1 text-xs text-red-600" x-text="formFuera.errores.ubicacion_destino?.[0]"></p>
                </div>
                <div>
                    <label class="{{ $claseEtiqueta }}" for="f-inicio">{{ __('Desde (opcional)') }}</label>
                    <input id="f-inicio" type="date" x-model="formFuera.fecha_inicio" class="{{ $claseCampo }}">
                    <p class="mt-1 text-xs text-red-600" x-text="formFuera.errores.fecha_inicio?.[0]"></p>
                </div>
                <div>
                    <label class="{{ $claseEtiqueta }}" for="f-fin">{{ __('Hasta (opcional)') }}</label>
                    <input id="f-fin" type="date" x-model="formFuera.fecha_fin" :min="formFuera.fecha_inicio" class="{{ $claseCampo }}">
                    <p class="mt-1 text-xs text-red-600" x-text="formFuera.errores.fecha_fin?.[0]"></p>
                </div>
                <details class="sm:col-span-2 text-sm text-gray-700">
                    <summary class="min-h-[32px] cursor-pointer font-medium">{{ __('Más detalles (no se imprimen)') }}</summary>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="{{ $claseEtiqueta }}" for="f-motivo">{{ __('Motivo') }}</label>
                            <input id="f-motivo" maxlength="255" x-model="formFuera.motivo" class="{{ $claseCampo }}">
                        </div>
                        <div>
                            <label class="{{ $claseEtiqueta }}" for="f-notas">{{ __('Notas') }}</label>
                            <input id="f-notas" maxlength="1000" x-model="formFuera.notas" class="{{ $claseCampo }}">
                        </div>
                    </div>
                </details>
                <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-gray-600" x-show="vistaFormFuera.length > 0">
                        {{ __('Así saldrá:') }} <span class="font-semibold text-gray-900" x-text="vistaFormFuera.join(' / ')"></span>
                    </p>
                    <div class="ml-auto flex gap-2">
                        <x-secondary-button type="button" @click="formFuera = null">{{ __('Cancelar') }}</x-secondary-button>
                        <x-primary-button x-bind:disabled="formFuera.enviando" class="disabled:opacity-60">{{ __('Guardar') }}</x-primary-button>
                    </div>
                </div>
            </form>
        </template>
    </section>

    {{-- Personal de otra coordinación que llegó --}}
    <section class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('¿Hay personal de otra coordinación en tu estación?') }}</h3>
                <p class="mt-0.5 text-sm text-gray-600">{{ __('No es de tu plantilla pero está aquí hoy. Se imprime en la sección E.a.') }}</p>
            </div>
            <x-secondary-button type="button" x-show="puedeGestionar && !formVisitante" @click="abrirVisitante()">{{ __('Agregar persona') }}</x-secondary-button>
        </div>

        <p x-show="visitantes.length === 0 && !formVisitante" class="mt-3 text-sm text-gray-500">{{ __('Nadie de otra coordinación en esta fecha.') }}</p>

        <ul class="mt-3 divide-y divide-gray-100" x-show="visitantes.length > 0">
            <template x-for="v in visitantes" :key="v.id">
                <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900" x-text="v.nombre"></p>
                        <p class="text-xs text-gray-600" x-text="lineasDeVisitante(v).join(' · ')"></p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3 text-sm" x-show="puedeGestionar">
                        <button type="button" @click="abrirVisitante(v)" class="min-h-[40px] font-medium text-brand-green underline">{{ __('Editar') }}</button>
                        <button type="button" x-show="confirmando !== `v${v.id}`" @click="confirmando = `v${v.id}`" class="min-h-[40px] font-medium text-red-700 underline">{{ __('Quitar') }}</button>
                        <button type="button" x-show="confirmando === `v${v.id}`" @click="quitarVisitante(v)" class="min-h-[40px] rounded-md bg-red-700 px-3 font-medium text-white">{{ __('¿Seguro? Quitar') }}</button>
                    </div>
                </li>
            </template>
        </ul>

        <template x-if="formVisitante">
            <form @submit.prevent="guardarVisitante()" class="mt-3 grid gap-3 border-t border-brand-green/10 pt-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $claseEtiqueta }}" for="v-nombre">{{ __('Nombre completo') }}</label>
                    <input id="v-nombre" list="lista-visitantes" maxlength="255" required x-model="formVisitante.nombre" @change="autocompletarVisitante()" class="{{ $claseCampo }}">
                    <p class="mt-1 text-xs text-red-600" x-text="formVisitante.errores.nombre?.[0]"></p>
                </div>
                <div>
                    <label class="{{ $claseEtiqueta }}" for="v-numero">{{ __('No. de trabajador') }}</label>
                    <input id="v-numero" maxlength="255" x-model="formVisitante.no_trabajador" class="{{ $claseCampo }}">
                    <p class="mt-1 text-xs text-red-600" x-text="formVisitante.errores.no_trabajador?.[0]"></p>
                </div>
                <div>
                    <label class="{{ $claseEtiqueta }}" for="v-origen">{{ __('¿De qué coordinación viene?') }}</label>
                    <input id="v-origen" list="lista-origenes" maxlength="255" required x-model="formVisitante.direccion_origen" placeholder="{{ __('Ej. CGGIF') }}" class="{{ $claseCampo }}">
                    <p class="mt-1 text-xs text-red-600" x-text="formVisitante.errores.direccion_origen?.[0]"></p>
                </div>
                <div>
                    <label class="{{ $claseEtiqueta }}" for="v-inicio">{{ __('Desde (opcional)') }}</label>
                    <input id="v-inicio" type="date" x-model="formVisitante.fecha_inicio" class="{{ $claseCampo }}">
                    <p class="mt-1 text-xs text-red-600" x-text="formVisitante.errores.fecha_inicio?.[0]"></p>
                </div>
                <div>
                    <label class="{{ $claseEtiqueta }}" for="v-fin">{{ __('Hasta (opcional)') }}</label>
                    <input id="v-fin" type="date" x-model="formVisitante.fecha_fin" :min="formVisitante.fecha_inicio" class="{{ $claseCampo }}">
                    <p class="mt-1 text-xs text-red-600" x-text="formVisitante.errores.fecha_fin?.[0]"></p>
                </div>
                <details class="sm:col-span-2 text-sm text-gray-700">
                    <summary class="min-h-[32px] cursor-pointer font-medium">{{ __('Más detalles (no se imprimen)') }}</summary>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="{{ $claseEtiqueta }}" for="v-motivo">{{ __('Motivo') }}</label>
                            <input id="v-motivo" maxlength="255" x-model="formVisitante.motivo" class="{{ $claseCampo }}">
                        </div>
                        <div>
                            <label class="{{ $claseEtiqueta }}" for="v-notas">{{ __('Notas') }}</label>
                            <input id="v-notas" maxlength="1000" x-model="formVisitante.notas" class="{{ $claseCampo }}">
                        </div>
                    </div>
                </details>
                <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-gray-600" x-show="vistaFormVisitante.length > 0">
                        {{ __('Así saldrá:') }} <span class="font-semibold text-gray-900" x-text="vistaFormVisitante.join(' / ')"></span>
                    </p>
                    <div class="ml-auto flex gap-2">
                        <x-secondary-button type="button" @click="formVisitante = null">{{ __('Cancelar') }}</x-secondary-button>
                        <x-primary-button x-bind:disabled="formVisitante.enviando" class="disabled:opacity-60">{{ __('Guardar') }}</x-primary-button>
                    </div>
                </div>
            </form>
        </template>
    </section>
</div>
