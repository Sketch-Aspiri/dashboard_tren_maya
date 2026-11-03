{{-- Asignar la estación de las filas de la tabla activa leyéndola de una columna.
     Primero pide al servidor una vista previa (coincidencias y valores sin
     coincidencia, sin escribir nada) y solo entonces permite aplicar. --}}
<div x-data="{
        abierta: false,
        columna: '',
        sobrescribir: false,
        cargando: false,
        error: '',
        previa: null,
        abrir() { this.abierta = true; this.previa = null; this.error = ''; },
        async previsualizar() {
            this.previa = null; this.error = '';
            if (!this.columna) { return; }
            this.cargando = true;
            try {
                const respuesta = await fetch(@js(route('secciones.tablas.estaciones.previsualizar', [$seccion, $tablaActiva])), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ columna: this.columna, sobrescribir: this.sobrescribir }),
                });
                const cuerpo = await respuesta.json();
                if (!respuesta.ok) { throw new Error(cuerpo?.message ?? @js(__('No se pudo calcular la vista previa.'))); }
                this.previa = cuerpo.data;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.cargando = false;
            }
        },
     }"
     @etiquetar-estacion.window="abrir()"
     @keydown.escape.window="abierta = false">
    <div x-show="abierta" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 p-0 sm:items-center sm:p-4"
         role="dialog" aria-modal="true" aria-labelledby="titulo-etiquetar-estacion">
        <form method="POST" action="{{ route('secciones.tablas.estaciones.aplicar', [$seccion, $tablaActiva]) }}"
              class="flex max-h-[92dvh] w-full max-w-xl flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            @csrf
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                <h3 id="titulo-etiquetar-estacion" class="font-heading text-lg font-semibold text-brand-green">{{ __('Asignar estación por columna') }}</h3>
                <button type="button" @click="abierta = false" class="min-h-[44px] min-w-[44px] rounded-md text-gray-500 hover:bg-gray-100" aria-label="{{ __('Cerrar') }}">✕</button>
            </div>

            <div class="space-y-4 overflow-y-auto px-5 py-4 text-sm">
                <p class="text-gray-600">{{ __('Elige la columna que trae el nombre de la estación. Las filas se etiquetan con la estación que coincida; las demás quedan sin estación y solo las ve la zona.') }}</p>

                <div>
                    <label for="columna-estacion" class="block font-medium text-gray-700">{{ __('Columna') }}</label>
                    <select id="columna-estacion" name="columna" x-model="columna" @change="previsualizar()" required
                            class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                        <option value="">{{ __('Selecciona una columna') }}</option>
                        @foreach ($columnas->whereIn('tipo', [\App\Enums\TipoColumna::Texto, \App\Enums\TipoColumna::Lista]) as $columnaTexto)
                            <option value="{{ $columnaTexto->clave }}">{{ $columnaTexto->nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <label class="flex min-h-[44px] items-center gap-2 text-gray-700">
                    <input type="checkbox" name="sobrescribir" value="1" x-model="sobrescribir" @change="previsualizar()"
                           class="rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                    {{ __('Reemplazar también las filas que ya tienen estación') }}
                </label>

                <p x-show="cargando" x-cloak class="text-gray-500" role="status">{{ __('Calculando…') }}</p>
                <p x-show="error" x-cloak x-text="error" class="text-red-700" role="alert"></p>

                <div x-show="previa" x-cloak class="space-y-3 rounded-md bg-brand-mist p-3">
                    <p class="font-medium text-brand-green">
                        <span x-text="Object.values(previa?.coincidencias ?? {}).reduce((a, b) => a + b, 0)"></span>
                        {{ __('filas se etiquetarán') }}
                        <span class="font-normal text-gray-600">(<span x-text="previa?.total"></span> {{ __('en la tabla') }}, <span x-text="previa?.ya_etiquetadas"></span> {{ __('ya tenían estación') }})</span>
                    </p>
                    <ul class="space-y-0.5">
                        <template x-for="(cuantas, nombre) in (previa?.coincidencias ?? {})" :key="nombre">
                            <li class="flex justify-between"><span x-text="nombre"></span><span x-text="cuantas"></span></li>
                        </template>
                    </ul>
                    <template x-if="Object.keys(previa?.sin_coincidencia ?? {}).length > 0">
                        <div>
                            <p class="font-medium text-amber-800">{{ __('Valores sin coincidencia (quedan sin estación):') }}</p>
                            <ul class="mt-1 space-y-0.5 text-gray-700">
                                <template x-for="(cuantas, valor) in previa.sin_coincidencia" :key="valor">
                                    <li class="flex justify-between"><span class="truncate" x-text="valor"></span><span x-text="cuantas"></span></li>
                                </template>
                            </ul>
                        </div>
                    </template>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-gray-100 px-5 py-4">
                <button type="button" @click="abierta = false" class="min-h-[44px] rounded-md px-4 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Cancelar') }}</button>
                <x-primary-button x-bind:disabled="!previa || cargando">{{ __('Aplicar') }}</x-primary-button>
            </div>
        </form>
    </div>
</div>
