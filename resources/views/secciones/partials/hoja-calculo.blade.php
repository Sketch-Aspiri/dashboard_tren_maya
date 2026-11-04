{{-- Hoja de cálculo (cuadrícula tipo Excel) de la tabla activa. La lógica
     vive en resources/js/hoja (Alpine.data('hojaCalculo')); los datos llegan
     por JSON (secciones.hoja.show) y cada cambio se guarda por lotes. Lo que
     el usuario puede hacer lo decide el servidor (permisos en la respuesta y
     Policy en cada petición). Variables: $seccion, $tablaActiva, $tablas,
     $puedeEditarSeccion, $puedeCrearFila. --}}
@php
    $configHoja = [
        'tabla' => $tablaActiva->id,
        'maxCeldasLote' => (int) config('secciones.hoja_max_celdas_lote'),
        'maxFilasLote' => (int) config('secciones.hoja_max_filas_lote'),
        // La ruta de fotos exige "<40 hex>.jpg": se genera con uno válido y se cambia por el marcador.
        'urlFoto' => str_replace(str_repeat('0', 40).'.jpg', '__FOTO__', route('secciones.fotos.show', [$seccion, str_repeat('0', 40).'.jpg'])),
        'puedeAbrirRegistro' => $puedeCrearFila,
        'urls' => [
            'hoja' => route('secciones.hoja.show', $seccion),
            'celdas' => route('secciones.hoja.celdas', $seccion),
            'insertarFilas' => route('secciones.hoja.filas.insertar', $seccion),
            'eliminarFilas' => route('secciones.hoja.filas.eliminar', $seccion),
            'insertarColumna' => route('secciones.hoja.columnas.insertar', $seccion),
            'columna' => route('secciones.hoja.columnas.actualizar', [$seccion, '__ID__']),
            'diseno' => route('secciones.hoja.diseno', $seccion),
            'ordenar' => route('secciones.hoja.ordenar', $seccion),
        ],
    ];
@endphp

<div x-data="hojaCalculo(@js($configHoja))"
     :class="pantallaCompleta ? 'hoja-pantalla-completa fixed inset-0 z-40 overflow-auto bg-white p-3' : 'relative'"
     class="rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">

    {{-- Barra de herramientas (mousedown.prevent: el foco se queda en la cuadrícula) --}}
    <div class="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-gray-50 px-2 py-1.5" role="toolbar" aria-label="{{ __('Herramientas de la hoja') }}" @mousedown.prevent>
        <template x-if="editable()">
            <div class="flex flex-wrap items-center gap-0.5">
                <button type="button" class="hoja-boton" :disabled="!puedeDeshacer" @click="deshacerCambio()" title="{{ __('Deshacer (Ctrl+Z)') }}" aria-label="{{ __('Deshacer') }}">↶</button>
                <button type="button" class="hoja-boton" :disabled="!puedeRehacer" @click="rehacerCambio()" title="{{ __('Rehacer (Ctrl+Y)') }}" aria-label="{{ __('Rehacer') }}">↷</button>
                <span class="hoja-separador" aria-hidden="true"></span>

                <label class="sr-only" for="hoja-tamano">{{ __('Tamaño de letra') }}</label>
                <select id="hoja-tamano" class="h-8 rounded border-gray-300 py-0 pl-2 pr-7 text-sm" @mousedown.stop
                        :value="estiloActivo.tam ?? ''" @change="ponerEstilo('tam', $event.target.value ? Number($event.target.value) : null)">
                    <option value="">11</option>
                    <template x-for="t in tamanos" :key="t"><option :value="t" x-text="t"></option></template>
                </select>
                <button type="button" class="hoja-boton font-bold" :class="estiloActivo.n && 'hoja-boton-activo'" @click="alternarEstilo('n')" title="{{ __('Negrita (Ctrl+B)') }}" aria-label="{{ __('Negrita') }}">N</button>
                <button type="button" class="hoja-boton italic" :class="estiloActivo.k && 'hoja-boton-activo'" @click="alternarEstilo('k')" title="{{ __('Cursiva (Ctrl+I)') }}" aria-label="{{ __('Cursiva') }}">K</button>
                <button type="button" class="hoja-boton underline" :class="estiloActivo.s && 'hoja-boton-activo'" @click="alternarEstilo('s')" title="{{ __('Subrayado (Ctrl+U)') }}" aria-label="{{ __('Subrayado') }}">S</button>
                <button type="button" class="hoja-boton line-through" :class="estiloActivo.t && 'hoja-boton-activo'" @click="alternarEstilo('t')" title="{{ __('Tachado') }}" aria-label="{{ __('Tachado') }}">T</button>

                @foreach (['color' => ['A', __('Color de texto')], 'fondo' => ['▧', __('Color de relleno')]] as $propiedad => [$icono, $etiqueta])
                    <div class="relative" x-data="{ abierto: false }" @click.outside="abierto = false">
                        <button type="button" class="hoja-boton" @click="abierto = !abierto" title="{{ $etiqueta }}" aria-label="{{ $etiqueta }}" :aria-expanded="abierto.toString()">
                            <span class="border-b-4 px-0.5 leading-none" :style="{ borderColor: estiloActivo['{{ $propiedad }}'] || '{{ $propiedad === 'color' ? '#000000' : '#ffffff' }}' }">{{ $icono }}</span>
                        </button>
                        <div x-show="abierto" x-cloak class="absolute left-0 top-9 z-50 w-48 rounded-md border border-gray-200 bg-white p-2 shadow-lg">
                            <div class="grid grid-cols-6 gap-1">
                                <template x-for="color in paleta" :key="color">
                                    <button type="button" class="h-6 w-6 rounded border border-gray-300" :style="{ backgroundColor: color }" :aria-label="color"
                                            @click="ponerEstilo('{{ $propiedad }}', color); abierto = false"></button>
                                </template>
                            </div>
                            <button type="button" class="mt-2 w-full rounded border border-gray-200 py-1 text-xs text-gray-600 hover:bg-gray-100" @click="ponerEstilo('{{ $propiedad }}', null); abierto = false">{{ __('Sin color') }}</button>
                        </div>
                    </div>
                @endforeach
                <span class="hoja-separador" aria-hidden="true"></span>

                <button type="button" class="hoja-boton" :class="estiloActivo.alin === 'izq' && 'hoja-boton-activo'" @click="ponerEstilo('alin', estiloActivo.alin === 'izq' ? null : 'izq')" title="{{ __('Alinear a la izquierda') }}" aria-label="{{ __('Alinear a la izquierda') }}">⫷</button>
                <button type="button" class="hoja-boton" :class="estiloActivo.alin === 'centro' && 'hoja-boton-activo'" @click="ponerEstilo('alin', estiloActivo.alin === 'centro' ? null : 'centro')" title="{{ __('Centrar') }}" aria-label="{{ __('Centrar') }}">≡</button>
                <button type="button" class="hoja-boton" :class="estiloActivo.alin === 'der' && 'hoja-boton-activo'" @click="ponerEstilo('alin', estiloActivo.alin === 'der' ? null : 'der')" title="{{ __('Alinear a la derecha') }}" aria-label="{{ __('Alinear a la derecha') }}">⫸</button>
                <button type="button" class="hoja-boton text-xs" :class="estiloActivo.a && 'hoja-boton-activo'" @click="alternarEstilo('a')" title="{{ __('Ajustar texto') }}">↵ {{ __('Ajustar') }}</button>
                <span class="hoja-separador" aria-hidden="true"></span>

                <button type="button" class="hoja-boton text-xs" @click="combinar()" title="{{ __('Combinar celdas') }}">⊞ {{ __('Combinar') }}</button>
                <button type="button" class="hoja-boton text-xs" @click="separar()" title="{{ __('Separar celdas') }}">{{ __('Separar') }}</button>
                <div class="relative" x-data="{ abierto: false }" @click.outside="abierto = false">
                    <button type="button" class="hoja-boton text-xs" @click="abierto = !abierto" :aria-expanded="abierto.toString()">▦ {{ __('Formato de tabla') }} ▾</button>
                    <div x-show="abierto" x-cloak class="absolute left-0 top-9 z-50 w-48 rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                        <template x-for="estilo in estilosTabla" :key="estilo.clave">
                            <button type="button" class="hoja-menu-item flex items-center gap-2" @click="formatoTabla(estilo.clave); abierto = false">
                                <span class="inline-block h-4 w-6 rounded-sm" :style="{ background: `linear-gradient(${estilo.encabezado} 40%, ${estilo.banda} 40%)` }"></span>
                                <span x-text="estilo.nombre"></span>
                            </button>
                        </template>
                        <button type="button" class="hoja-menu-item border-t border-gray-100" @click="quitarTabla(); abierto = false">{{ __('Quitar formato de tabla') }}</button>
                    </div>
                </div>
                <button type="button" class="hoja-boton text-xs" @click="borrarFormato()" title="{{ __('Borrar formato de las celdas') }}">⌫ {{ __('Formato') }}</button>
                <span class="hoja-separador" aria-hidden="true"></span>

                <button type="button" class="hoja-boton" @click="autosuma()" title="{{ __('Autosuma: suma la columna de arriba') }}" aria-label="{{ __('Autosuma') }}">Σ</button>
                <button type="button" class="hoja-boton text-xs" @click="ordenar('asc')" title="{{ __('Ordenar la hoja por esta columna, de A a Z') }}">A→Z</button>
                <button type="button" class="hoja-boton text-xs" @click="ordenar('desc')" title="{{ __('Ordenar la hoja por esta columna, de Z a A') }}">Z→A</button>
            </div>
        </template>
        <template x-if="!editable() && !cargando">
            <span class="px-2 text-xs text-gray-500" x-text="truncada ? @js(__('La hoja es muy grande para editarse aquí: se muestra en solo lectura.')) : @js(__('Solo lectura'))"></span>
        </template>
        <span class="ms-auto flex items-center gap-2 text-xs text-gray-500">
            <span x-show="pendientes > 0" x-cloak>{{ __('Guardando…') }}</span>
            <span x-show="pendientes === 0 && !cargando && editable()" x-cloak>{{ __('Guardado') }}</span>
            <button type="button" class="hoja-boton" @click="alternarPantallaCompleta()" :title="pantallaCompleta ? @js(__('Salir de pantalla completa')) : @js(__('Pantalla completa'))" :aria-label="pantallaCompleta ? @js(__('Salir de pantalla completa')) : @js(__('Pantalla completa'))">⛶</button>
        </span>
    </div>

    {{-- Cuadro de nombre + barra de fórmulas --}}
    <div class="flex items-stretch gap-2 border-b border-gray-200 px-2 py-1">
        <label for="hoja-nombre" class="sr-only">{{ __('Celda activa') }}</label>
        <input id="hoja-nombre" type="text" x-model="nombreActiva" @keydown.enter.prevent="irANombre()" @focus="$event.target.select()"
               class="h-8 w-24 rounded border-gray-300 px-2 font-mono text-xs" autocomplete="off" spellcheck="false">
        <span class="flex items-center px-1 font-serif text-sm italic text-gray-500" aria-hidden="true">fx</span>
        <label for="hoja-formula" class="sr-only">{{ __('Contenido de la celda (barra de fórmulas)') }}</label>
        <input id="hoja-formula" type="text" x-model="textoBarra" :readonly="!editable()"
               @focus="enfocarBarra()" @keydown.enter.prevent="confirmarBarra($event)" @keydown.escape.prevent="confirmarBarra($event)"
               @blur="editandoBarra && confirmarBarra(null)"
               placeholder="{{ __('Escribe un valor o una fórmula, p. ej. =SUMA(A1:A10)') }}"
               class="h-8 min-w-0 flex-1 rounded border-gray-300 px-2 font-mono text-sm" autocomplete="off" spellcheck="false">
    </div>

    <div x-show="aviso" x-cloak role="status" aria-live="polite"
         :class="avisoEsError ? 'bg-red-50 text-red-800 border-red-200' : 'bg-brand-mist text-brand-green border-brand-green/10'"
         class="border-b px-3 py-1.5 text-sm" x-text="aviso"></div>

    <template x-if="errorCarga">
        <div class="p-6 text-center text-sm text-red-700" role="alert">
            <p x-text="errorCarga"></p>
            <button type="button" class="mt-2 font-semibold underline" @click="cargando = true; recargar()">{{ __('Reintentar') }}</button>
        </div>
    </template>

    {{-- Cuadrícula --}}
    <div x-ref="rejilla" class="hoja-rejilla" tabindex="0" role="grid" aria-label="{{ __('Hoja :nombre', ['nombre' => $tablaActiva->nombre]) }}"
         :aria-busy="cargando.toString()" aria-describedby="hoja-ayuda-teclado"
         @keydown="teclado($event)">
        <div class="hoja-esquina" @mousedown.prevent="seleccionarTodo()" title="{{ __('Seleccionar todo') }}"></div>
        <div class="hoja-panel-encabezados" @mousedown="alPresionarEncabezado($event)" @contextmenu="abrirMenu($event, 'columna')"
             @dblclick="permisos.estructura && renombrarColumna()">
            <div x-ref="encabezados" class="relative h-10"></div>
        </div>
        <div class="hoja-panel-numeros" @mousedown="alPresionarNumero($event)" @contextmenu="abrirMenu($event, 'fila')">
            <div x-ref="numeros" class="relative w-12"></div>
        </div>
        <div x-ref="cuerpo" class="hoja-cuerpo">
            <div x-ref="lienzo" class="relative" @mousedown="alPresionar($event)" @dblclick="abrirEditor(null, false)" @contextmenu="abrirMenu($event, 'celda')">
                <div x-ref="celdas"></div>
                <div x-ref="seleccion" class="hoja-seleccion"></div>
                <div x-ref="activa" class="hoja-activa"></div>
                <textarea x-ref="editor" x-show="editorVisible && editorTipo === 'texto'" class="hoja-editor" rows="1"
                          aria-label="{{ __('Editar celda') }}" @mousedown.stop @blur="editorVisible && editorTipo === 'texto' && confirmarEdicion(0, 0)"></textarea>
                <select x-ref="selector" x-show="editorVisible && editorTipo === 'opciones'" class="hoja-editor py-0"
                        aria-label="{{ __('Elegir valor') }}" @mousedown.stop @change="confirmarEdicion(1, 0)" @blur="editorVisible && editorTipo === 'opciones' && cancelarEdicion()">
                    <option value="">—</option>
                    <template x-for="opcion in opcionesEditor" :key="opcion"><option :value="opcion" x-text="opcion"></option></template>
                </select>
            </div>
        </div>
        <div x-show="cargando" class="absolute inset-0 z-40 flex items-center justify-center bg-white/70 text-sm text-gray-600">{{ __('Cargando la hoja…') }}</div>
        <div x-show="!cargando && sinColumnas" x-cloak class="absolute inset-0 z-30 flex items-center justify-center text-sm text-gray-500">{{ __('Esta hoja no tiene columnas.') }}</div>
    </div>
    <p id="hoja-ayuda-teclado" class="sr-only">{{ __('Usa las flechas para moverte, Enter o F2 para editar, Ctrl+C y Ctrl+V para copiar y pegar, Ctrl+Z para deshacer.') }}</p>

    {{-- Barra de estado + pestañas de hojas --}}
    <div class="flex flex-col gap-2 border-t border-gray-200 bg-gray-50 px-2 py-1.5 sm:flex-row sm:items-center sm:justify-between">
        <nav class="flex min-w-0 flex-wrap items-center gap-1" aria-label="{{ __('Hojas de la sección') }}">
            @foreach ($tablas as $hoja)
                @php $esActiva = $hoja->id === $tablaActiva->id; @endphp
                <a href="{{ route('secciones.show', [$seccion, 'tabla' => $hoja->id, 'tab' => 'tabla']) }}" title="{{ $hoja->nombre }}"
                   @if ($esActiva) aria-current="page" @endif
                   @class([
                       'inline-flex h-8 max-w-[14rem] items-center truncate rounded-b-md border px-3 text-sm',
                       'border-brand-green border-t-4 border-t-brand-teal bg-white font-semibold text-brand-green' => $esActiva,
                       'border-gray-200 bg-gray-100 text-gray-700 hover:bg-white' => ! $esActiva,
                   ])>{{ $hoja->nombre }}@if ($hoja->esEspejo()) <span class="ms-1 text-xs text-gray-400" title="{{ __('Sincronizada con la nube') }}">☁</span>@endif</a>
            @endforeach

            @if ($puedeEditarSeccion || auth()->user()->can('update', $seccion))
                <div class="relative" x-data="{ abierto: null }" @click.outside="abierto = null" @keydown.escape="abierto = null">
                    <button type="button" class="hoja-boton" @click="abierto = abierto === 'nueva' ? null : 'nueva'" title="{{ __('Nueva hoja') }}" aria-label="{{ __('Nueva hoja') }}">＋</button>
                    <button type="button" class="hoja-boton" @click="abierto = abierto === 'opciones' ? null : 'opciones'" title="{{ __('Opciones de la hoja actual') }}" aria-label="{{ __('Opciones de la hoja actual') }}">⋯</button>

                    <form x-show="abierto === 'nueva'" x-cloak method="POST" action="{{ route('secciones.tablas.store', $seccion) }}"
                          class="absolute bottom-10 left-0 z-50 flex w-72 gap-2 rounded-md border border-gray-200 bg-white p-3 shadow-lg">
                        @csrf
                        <label for="hoja-nueva-nombre" class="sr-only">{{ __('Nombre de la hoja nueva') }}</label>
                        <input id="hoja-nueva-nombre" name="nombre" required maxlength="160" value="{{ __('Hoja :n', ['n' => $tablas->count() + 1]) }}"
                               class="min-w-0 flex-1 rounded-md border-gray-300 text-sm" x-init="$watch('abierto', v => v === 'nueva' && $nextTick(() => $el.select()))">
                        <button type="submit" class="rounded-md bg-brand-green px-3 text-sm font-semibold text-white hover:bg-brand-green-dark">{{ __('Crear') }}</button>
                    </form>

                    <div x-show="abierto === 'opciones'" x-cloak class="absolute bottom-10 left-0 z-50 w-72 space-y-2 rounded-md border border-gray-200 bg-white p-3 shadow-lg">
                        <p class="truncate text-xs font-semibold uppercase text-gray-500">{{ $tablaActiva->nombre }}</p>
                        @unless ($tablaActiva->esEspejo())
                            <form method="POST" action="{{ route('secciones.tablas.update', [$seccion, $tablaActiva]) }}" class="flex gap-2">
                                @csrf @method('PUT')
                                <label for="hoja-renombrar" class="sr-only">{{ __('Nuevo nombre de la hoja') }}</label>
                                <input id="hoja-renombrar" name="nombre" required maxlength="160" value="{{ $tablaActiva->nombre }}" class="min-w-0 flex-1 rounded-md border-gray-300 text-sm">
                                <button type="submit" class="rounded-md border border-brand-green/30 px-2 text-sm text-brand-green hover:bg-brand-mist">{{ __('Renombrar') }}</button>
                            </form>
                        @endunless
                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('secciones.tablas.duplicar', [$seccion, $tablaActiva]) }}">
                                @csrf
                                <button type="submit" class="rounded-md border border-gray-200 px-2 py-1 text-sm hover:bg-gray-100">{{ __('Duplicar') }}</button>
                            </form>
                            @foreach (['arriba' => __('← Mover'), 'abajo' => __('Mover →')] as $direccion => $etiqueta)
                                <form method="POST" action="{{ route('secciones.tablas.mover', [$seccion, $tablaActiva]) }}">
                                    @csrf
                                    <input type="hidden" name="direccion" value="{{ $direccion }}">
                                    <button type="submit" class="rounded-md border border-gray-200 px-2 py-1 text-sm hover:bg-gray-100">{{ $etiqueta }}</button>
                                </form>
                            @endforeach
                        </div>
                        @if (! $tablaActiva->esEspejo() && $tablas->count() > 1)
                            <x-confirmar-eliminar
                                :action="route('secciones.tablas.destroy', [$seccion, $tablaActiva])"
                                :etiqueta="__('Eliminar hoja')"
                                :aria-label="__('Eliminar hoja «:nombre»', ['nombre' => $tablaActiva->nombre])"
                                :pregunta="__('¿Eliminar la hoja «:nombre» y todos sus registros?', ['nombre' => $tablaActiva->nombre])"
                            />
                        @endif
                    </div>
                </div>
            @endif
        </nav>
        <p class="shrink-0 text-xs text-gray-600" aria-live="polite" x-text="resumen"></p>
    </div>

    {{-- Menú contextual --}}
    <div x-show="menu.abierto" x-cloak @click.outside="menu.abierto = false" @keydown.escape.window="menu.abierto = false" @mousedown.prevent
         class="absolute z-50 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-xl" :style="{ left: menu.x + 'px', top: menu.y + 'px' }" role="menu">
        <template x-if="editable()">
            <div>
                <template x-if="menu.tipo !== 'columna'">
                    <div>
                        <button type="button" role="menuitem" class="hoja-menu-item" @click="insertarFilas(false)">{{ __('Insertar filas arriba') }}</button>
                        <button type="button" role="menuitem" class="hoja-menu-item" @click="insertarFilas(true)">{{ __('Insertar filas abajo') }}</button>
                        <button type="button" role="menuitem" class="hoja-menu-item text-red-700" @click="eliminarFilas()">{{ __('Eliminar filas') }}</button>
                        @if ($puedeCrearFila)
                            <button type="button" role="menuitem" class="hoja-menu-item border-t border-gray-100" @click="abrirRegistro()">{{ __('Abrir registro (fotos y estación)') }}</button>
                        @endif
                    </div>
                </template>
                <template x-if="permisos.estructura && menu.tipo !== 'fila'">
                    <div class="border-t border-gray-100">
                        <button type="button" role="menuitem" class="hoja-menu-item" @click="insertarColumna(false)">{{ __('Insertar columna a la izquierda') }}</button>
                        <button type="button" role="menuitem" class="hoja-menu-item" @click="insertarColumna(true)">{{ __('Insertar columna a la derecha') }}</button>
                        <button type="button" role="menuitem" class="hoja-menu-item" @click="menu.abierto = false; renombrarColumna()">{{ __('Renombrar columna') }}</button>
                        <a role="menuitem" class="hoja-menu-item" href="{{ route('secciones.columnas.index', [$seccion, 'tabla' => $tablaActiva->id]) }}">{{ __('Tipo de dato y opciones…') }}</a>
                        <button type="button" role="menuitem" class="hoja-menu-item text-red-700" @click="eliminarColumna()">{{ __('Eliminar columna') }}</button>
                    </div>
                </template>
                <div class="border-t border-gray-100">
                    <button type="button" role="menuitem" class="hoja-menu-item" @click="menu.abierto = false; vaciarSeleccion()">{{ __('Borrar contenido') }}</button>
                    <button type="button" role="menuitem" class="hoja-menu-item" @click="borrarFormato()">{{ __('Borrar formato') }}</button>
                    <button type="button" role="menuitem" class="hoja-menu-item" @click="ordenar('asc')">{{ __('Ordenar hoja A → Z') }}</button>
                    <button type="button" role="menuitem" class="hoja-menu-item" @click="ordenar('desc')">{{ __('Ordenar hoja Z → A') }}</button>
                </div>
            </div>
        </template>
        <p class="px-3 py-1.5 text-xs text-gray-500">{{ __('Copiar: Ctrl+C · Pegar: Ctrl+V') }}</p>
    </div>

    {{-- Diálogo propio (confirmar / pedir un nombre) --}}
    <div x-show="dialogo.abierto" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/40 p-4"
         role="dialog" aria-modal="true" aria-labelledby="hoja-dialogo-titulo" @keydown.escape.prevent.stop="cerrarDialogo(false)">
        <form class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl" @submit.prevent="cerrarDialogo(true)">
            <h3 id="hoja-dialogo-titulo" class="font-heading text-base font-semibold text-brand-green" x-text="dialogo.titulo"></h3>
            <p class="mt-2 text-sm text-gray-700" x-text="dialogo.texto"></p>
            <template x-if="dialogo.conCampo">
                <input x-ref="campoDialogo" type="text" x-model="dialogo.valor" maxlength="120" required
                       class="mt-3 block w-full rounded-md border-gray-300 text-sm" aria-labelledby="hoja-dialogo-titulo">
            </template>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="min-h-[40px] rounded-md border border-gray-300 px-4 text-sm" @click="cerrarDialogo(false)">{{ __('Cancelar') }}</button>
                <button type="submit" x-ref="aceptarDialogo" class="min-h-[40px] rounded-md px-4 text-sm font-semibold text-white"
                        :class="dialogo.peligro ? 'bg-red-700 hover:bg-red-800' : 'bg-brand-green hover:bg-brand-green-dark'">{{ __('Aceptar') }}</button>
            </div>
        </form>
    </div>
</div>
