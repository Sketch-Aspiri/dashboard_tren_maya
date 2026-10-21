{{-- Vista previa de documentos dentro del sistema. Se abre con el evento
     "vista-previa" ({ url, nombre }). Todo el contenido se pinta con x-text
     (escapado); la única URL dinámica (iframe/img) viene del servidor. --}}
<div x-data="{
        abierto: false, cargando: false, error: null, datos: null, url: null, nombre: '',
        async abrir(url, nombre, hoja = null) {
            this.abierto = true; this.cargando = true; this.error = null; this.nombre = nombre; this.url = url;
            if (hoja === null) { this.datos = null; }
            try {
                const respuesta = await fetch(hoja === null ? url : url + (url.includes('?') ? '&' : '?') + 'hoja=' + hoja, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const cuerpo = await respuesta.json();
                if (!respuesta.ok || !cuerpo.success) { throw new Error('fallo'); }
                this.datos = cuerpo.data;
            } catch (e) {
                this.error = @js(__('No se pudo cargar la vista previa.'));
            } finally {
                this.cargando = false;
            }
        },
        cerrar() { this.abierto = false; this.datos = null; },
    }"
     @vista-previa.window="abrir($event.detail.url, $event.detail.nombre)"
     @keydown.escape.window="cerrar()">
    <div x-show="abierto" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-2 sm:p-6" role="dialog" aria-modal="true" :aria-label="nombre">
        <div class="flex max-h-full w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-xl" @click.outside="cerrar()">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <p class="truncate text-sm font-semibold text-brand-green" x-text="nombre"></p>
                <div class="flex shrink-0 items-center gap-2">
                    <a x-show="datos" :href="datos ? datos.url_descarga : '#'"
                       class="min-h-[36px] rounded-md px-2 py-1.5 text-sm font-medium text-brand-teal hover:bg-brand-mist">{{ __('Descargar') }}</a>
                    <button type="button" @click="cerrar()" class="min-h-[36px] rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Cerrar') }}</button>
                </div>
            </div>

            <div class="min-h-[200px] flex-1 overflow-auto p-4">
                <p x-show="cargando" class="text-sm text-gray-600">{{ __('Cargando…') }}</p>
                <p x-show="error" x-text="error" class="text-sm text-red-700" role="alert"></p>

                <template x-if="datos && datos.tipo === 'pdf'">
                    <iframe :src="datos.url" :title="nombre" class="h-[75vh] w-full rounded border border-gray-200"></iframe>
                </template>

                <template x-if="datos && datos.tipo === 'imagen'">
                    <img :src="datos.url" :alt="nombre" class="mx-auto max-h-[75vh] max-w-full">
                </template>

                <template x-if="datos && datos.tipo === 'tabla'">
                    <div class="space-y-3">
                        <div x-show="datos.hojas.length > 1" class="flex flex-wrap gap-1" role="tablist">
                            <template x-for="(hoja, indice) in datos.hojas" :key="indice">
                                <button type="button" role="tab" @click="abrir(url, nombre, indice)" :aria-selected="(indice === datos.hoja).toString()"
                                        :class="indice === datos.hoja ? 'bg-brand-green text-white' : 'bg-brand-mist text-brand-green'"
                                        class="min-h-[36px] rounded-md px-3 text-xs font-semibold" x-text="hoja"></button>
                            </template>
                        </div>
                        <p x-show="datos.truncado" class="text-xs text-amber-800">{{ __('Se muestran solo las primeras filas y columnas. Descarga el archivo para verlo completo.') }}</p>
                        <p x-show="datos.filas.length === 0" class="text-sm text-gray-600">{{ __('La hoja no tiene datos.') }}</p>
                        <div class="overflow-auto rounded border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-xs">
                                <tbody class="divide-y divide-gray-100">
                                    <template x-for="(fila, i) in datos.filas" :key="i">
                                        <tr :class="i === 0 ? 'bg-brand-mist font-semibold' : ''">
                                            <template x-for="(celda, j) in fila" :key="j">
                                                <td class="whitespace-nowrap px-2 py-1" x-text="celda"></td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>

                <template x-if="datos && (datos.tipo === 'documento' || datos.tipo === 'texto')">
                    <div class="space-y-4">
                        <p x-show="datos.truncado" class="text-xs text-amber-800">{{ __('Se muestra solo el inicio del texto. Descarga el archivo para verlo completo.') }}</p>
                        <pre class="whitespace-pre-wrap break-words font-sans text-sm text-gray-800" x-text="datos.texto"></pre>
                        <template x-for="(tabla, t) in (datos.tablas || [])" :key="t">
                            <div>
                                <p class="mb-1 text-xs font-semibold text-gray-600" x-text="tabla.nombre"></p>
                                <div class="overflow-auto rounded border border-gray-200">
                                    <table class="min-w-full divide-y divide-gray-200 text-xs">
                                        <tbody class="divide-y divide-gray-100">
                                            <template x-for="(fila, i) in tabla.filas" :key="i">
                                                <tr>
                                                    <template x-for="(celda, j) in fila" :key="j">
                                                        <td class="px-2 py-1" x-text="celda"></td>
                                                    </template>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="datos && datos.tipo === 'no_soportado'">
                    <div class="space-y-3 text-sm text-gray-700">
                        <p x-text="datos.mensaje"></p>
                        <a :href="datos.url_descarga" class="inline-flex min-h-[44px] items-center rounded-md bg-brand-green px-4 text-sm font-semibold text-white hover:bg-brand-green-dark">{{ __('Descargar archivo') }}</a>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
