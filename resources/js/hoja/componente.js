/**
 * Componente Alpine de la hoja de cálculo
 * (resources/views/secciones/partials/hoja-calculo.blade.php): carga,
 * selección, teclado, ratón, edición de celdas y portapapeles. Formato,
 * diseño, estructura y deshacer viven en ./acciones.js.
 *
 * El modelo (MotorHoja), el pintado (Rejilla) y la cola de peticiones
 * (ApiHoja) se guardan en un objeto de cierre, fuera del estado reactivo de
 * Alpine: envolverlos en Proxies sería lento y rompería sus Map internos.
 */
import { ApiHoja } from './api';
import { crearAcciones } from './acciones';
import { esFormula, leerCelda, letrasColumna, nombreCelda, trasladar } from './formulas';
import { MotorHoja } from './motor';
import { aTsv, deTsv } from './portapapeles';
import { Rejilla } from './rejilla';

const FILAS_EXTRA_EDITABLE = 30;
const MAX_CELDAS_RESUMEN = 50000;
const DURACION_AVISO_MS = 6000;

/**
 * ¿Cabe el valor en el tipo de la columna? (aviso previo; el servidor
 * valida de verdad). Una fórmula siempre cabe.
 */
export function valorAdmitido(columna, valor) {
    if (valor === null || valor === '' || esFormula(valor) || typeof valor === 'number' || typeof valor === 'boolean') {
        return columna.tipo !== 'foto' || valor === null || valor === '';
    }
    const texto = String(valor).trim();
    switch (columna.tipo) {
        case 'numero':
            return /^[-+]?\$?\s?[\d,]*\.?\d+%?$/.test(texto);
        case 'fecha':
            return /^\d{4}-\d{1,2}-\d{1,2}/.test(texto) || /^\d{1,2}[/\-.]\d{1,2}[/\-.]\d{2,4}$/.test(texto) || /^\d{1,2}(\s+de\s+|[\s\-/.]+)[a-záéíóú]+\.?/i.test(texto);
        case 'booleano':
            return ['si', 'sí', 's', 'x', 'true', 'verdadero', '1', 'yes', 'ok', 'activo', 'no', 'n', 'false', 'falso', '0', 'inactivo'].includes(texto.toLowerCase());
        case 'lista':
            return columna.opciones.includes(texto);
        case 'foto':
            return false;
        default:
            return true;
    }
}

/**
 * @param {{urls: Object<string, string>, tabla: number, maxCeldasLote: number, maxFilasLote: number, urlFoto: string, puedeAbrirRegistro: boolean}} config
 */
export function hojaCalculo(config) {
    const ctx = {
        motor: new MotorHoja({ columnas: [], filas: [], diseno: null }),
        rejilla: null,
        api: null,
        activa: { fila: 0, col: 0 },
        extremo: { fila: 0, col: 0 },
        arrastrando: null,
        editando: null,
        portapapeles: null,
        deshacer: [],
        rehacer: [],
        config,
    };

    return {
        cargando: true,
        errorCarga: '',
        aviso: '',
        avisoEsError: false,
        pendientes: 0,
        permisos: { editar: false, estructura: false },
        truncada: false,
        sinColumnas: false,
        nombreActiva: 'A1',
        textoBarra: '',
        editandoBarra: false,
        editorVisible: false,
        editorTipo: 'texto',
        opcionesEditor: [],
        estiloActivo: {},
        resumen: '',
        puedeDeshacer: false,
        puedeRehacer: false,
        pantallaCompleta: false,
        menu: { abierto: false, x: 0, y: 0, tipo: 'celda' },
        dialogo: { abierto: false, titulo: '', texto: '', valor: '', conCampo: false, peligro: false, resolver: null },
        temporizadorAviso: null,

        ...crearAcciones(ctx),

        async init() {
            ctx.rejilla = new Rejilla({
                cuerpo: this.$refs.cuerpo,
                lienzo: this.$refs.lienzo,
                celdas: this.$refs.celdas,
                encabezados: this.$refs.encabezados,
                numeros: this.$refs.numeros,
                seleccion: this.$refs.seleccion,
                activa: this.$refs.activa,
            }, ctx.motor, { filasExtra: 0, urlFoto: config.urlFoto });

            ctx.api = new ApiHoja(config, {
                alGuardar: (filas, lote) => this.alGuardar(filas, lote),
                alFallar: (error) => this.alFallar(error),
                alCambiarEstado: (pendientes) => { this.pendientes = pendientes; },
            });

            this.escucharPortapapeles();
            window.addEventListener('mousemove', (e) => this.moverArrastre(e));
            window.addEventListener('mouseup', () => this.terminarArrastre());
            window.addEventListener('beforeunload', (e) => {
                if (ctx.api.totalPendiente() > 0) {
                    e.preventDefault();
                }
            });
            new ResizeObserver(() => ctx.rejilla.programar()).observe(this.$refs.cuerpo);

            // En celular la hoja no se muestra (quedan las tarjetas): se carga hasta que haya pantalla para ella.
            const escritorio = window.matchMedia('(min-width: 640px)');
            if (escritorio.matches) {
                await this.recargar();
            } else {
                escritorio.addEventListener('change', () => this.cargando && this.recargar(), { once: true });
            }
        },

        // ------------------------------------------------------------ carga

        async recargar() {
            try {
                const datos = await ctx.api.cargar();
                ctx.motor = new MotorHoja(datos);
                this.permisos = datos.permisos;
                this.truncada = datos.truncada;
                this.sinColumnas = datos.columnas.length === 0;
                ctx.rejilla.opciones.filasExtra = this.editable() ? FILAS_EXTRA_EDITABLE : 0;
                ctx.rejilla.usarMotor(ctx.motor);
                this.errorCarga = '';
                this.seleccionar(Math.min(ctx.activa.fila, ctx.rejilla.totalFilas - 1), Math.min(ctx.activa.col, Math.max(0, datos.columnas.length - 1)));
            } catch (error) {
                this.errorCarga = error.message || 'No se pudo cargar la hoja.';
            } finally {
                this.cargando = false;
            }
        },

        editable() {
            return this.permisos.editar && !this.truncada;
        },

        alGuardar(filas, lote) {
            // Solo se toma el valor normalizado del servidor si la celda no se volvió a editar mientras tanto.
            for (const cambio of lote) {
                const pos = ctx.motor.posicionDeFila(cambio.fila);
                const col = ctx.motor.columnas.findIndex((c) => c.clave === cambio.columna);
                const servidor = filas[cambio.fila];
                if (pos < 0 || col < 0 || !servidor || !('valor' in cambio)) {
                    continue;
                }
                const local = ctx.motor.crudo(pos, col);
                const enviado = typeof cambio.valor === 'string' ? cambio.valor.trim() : cambio.valor;
                if (local === enviado || (local === null && (enviado === '' || enviado === null))) {
                    ctx.motor.poner(pos, col, { valor: servidor.datos[cambio.columna] ?? null });
                }
            }
            ctx.rejilla.programar();
            this.actualizarBarra();
        },

        async alFallar(error) {
            this.avisar(`No se guardó: ${error.message} Se recargó la hoja con lo último guardado.`, true);
            ctx.deshacer = [];
            ctx.rehacer = [];
            this.actualizarPilas();
            await ctx.api.cola;
            await this.recargar();
        },

        avisar(texto, esError = false) {
            this.aviso = texto;
            this.avisoEsError = esError;
            clearTimeout(this.temporizadorAviso);
            this.temporizadorAviso = setTimeout(() => { this.aviso = ''; }, DURACION_AVISO_MS);
        },

        // ------------------------------------------------------------ selección

        rango() {
            const f1 = Math.min(ctx.activa.fila, ctx.extremo.fila);
            const f2 = Math.max(ctx.activa.fila, ctx.extremo.fila);
            const c1 = Math.min(ctx.activa.col, ctx.extremo.col);
            const c2 = Math.max(ctx.activa.col, ctx.extremo.col);
            return ctx.motor.expandir({ f1, c1, f2, c2 });
        },

        /** Rango seleccionado limitado a las filas que existen. */
        rangoReal() {
            const r = this.rango();
            return { ...r, f2: Math.min(r.f2, ctx.motor.filas.length - 1) };
        },

        seleccionar(fila, col, extender = false) {
            const maxFila = Math.max(0, ctx.rejilla.totalFilas - 1);
            const maxCol = Math.max(0, ctx.motor.columnas.length - 1);
            const destino = { fila: Math.max(0, Math.min(maxFila, fila)), col: Math.max(0, Math.min(maxCol, col)) };
            if (extender) {
                ctx.extremo = destino;
            } else {
                const combinada = ctx.motor.combinacionEn(destino.fila, destino.col);
                ctx.activa = combinada ? { fila: combinada.f1, col: combinada.c1 } : destino;
                ctx.extremo = { ...ctx.activa };
            }
            ctx.rejilla.asegurarVisible(extender ? ctx.extremo.fila : ctx.activa.fila, extender ? ctx.extremo.col : ctx.activa.col);
            this.refrescarSeleccion();
        },

        refrescarSeleccion() {
            const r = this.rango();
            ctx.rejilla.pintarSeleccion(r, ctx.activa);
            this.nombreActiva = r.f1 === r.f2 && r.c1 === r.c2 || ctx.motor.combinacionEn(ctx.activa.fila, ctx.activa.col)
                ? nombreCelda(ctx.activa.fila, ctx.activa.col)
                : `${nombreCelda(r.f1, r.c1)}:${nombreCelda(r.f2, r.c2)}`;
            this.actualizarBarra();
            this.estiloActivo = { ...(ctx.motor.estilo(ctx.activa.fila, ctx.activa.col) ?? {}) };
            this.calcularResumen(r);
        },

        actualizarBarra() {
            if (!this.editandoBarra) {
                this.textoBarra = ctx.motor.textoEditable(ctx.activa.fila, ctx.activa.col);
            }
        },

        calcularResumen(r) {
            const celdas = (r.f2 - r.f1 + 1) * (r.c2 - r.c1 + 1);
            if (celdas < 2 || celdas > MAX_CELDAS_RESUMEN) {
                this.resumen = '';
                return;
            }
            let cuenta = 0;
            let numeros = 0;
            let suma = 0;
            for (let f = r.f1; f <= Math.min(r.f2, ctx.motor.filas.length - 1); f++) {
                for (let c = r.c1; c <= r.c2; c++) {
                    const valor = ctx.motor.valor(f, c);
                    if (valor !== null && valor !== '') {
                        cuenta++;
                    }
                    if (typeof valor === 'number') {
                        numeros++;
                        suma += valor;
                    }
                }
            }
            const formato = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 4 });
            this.resumen = numeros > 0
                ? `Promedio: ${formato.format(suma / numeros)} · Recuento: ${cuenta} · Suma: ${formato.format(suma)}`
                : `Recuento: ${cuenta}`;
        },

        /** Mueve la celda activa como las flechas, saltando celdas combinadas. */
        mover(dFila, dCol, extender = false) {
            const desde = extender ? ctx.extremo : ctx.activa;
            const combinada = !extender ? ctx.motor.combinacionEn(desde.fila, desde.col) : null;
            let fila = desde.fila + dFila;
            let col = desde.col + dCol;
            if (combinada) {
                fila = dFila > 0 ? combinada.f2 + 1 : dFila < 0 ? combinada.f1 - 1 : desde.fila;
                col = dCol > 0 ? combinada.c2 + 1 : dCol < 0 ? combinada.c1 - 1 : desde.col;
            }
            this.seleccionar(fila, col, extender);
        },

        irANombre() {
            const texto = this.nombreActiva.trim().toUpperCase();
            const [a, b] = texto.split(':').map(leerCelda);
            if (!a) {
                this.refrescarSeleccion();
                return;
            }
            this.seleccionar(a.fila, a.col);
            if (b) {
                this.seleccionar(b.fila, b.col, true);
            }
            this.$refs.rejilla.focus();
        },

        // ------------------------------------------------------------ teclado

        teclado(evento) {
            if (ctx.editando) {
                this.tecladoEditor(evento);
                return;
            }
            const ctrl = evento.ctrlKey || evento.metaKey;
            const tecla = evento.key;
            const flechas = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };

            if (flechas[tecla]) {
                evento.preventDefault();
                const [df, dc] = flechas[tecla];
                if (ctrl) {
                    this.saltarAlBorde(df, dc, evento.shiftKey);
                } else {
                    this.mover(df, dc, evento.shiftKey);
                }
                return;
            }
            if (ctrl && this.atajo(tecla.toLowerCase(), evento)) {
                evento.preventDefault();
                return;
            }
            switch (tecla) {
                case 'Tab':
                    evento.preventDefault();
                    this.mover(0, evento.shiftKey ? -1 : 1);
                    return;
                case 'Enter':
                    evento.preventDefault();
                    this.mover(evento.shiftKey ? -1 : 1, 0);
                    return;
                case 'F2':
                    evento.preventDefault();
                    this.abrirEditor(null, false);
                    return;
                case 'Delete':
                case 'Backspace':
                    evento.preventDefault();
                    this.vaciarSeleccion();
                    return;
                case 'PageDown':
                case 'PageUp': {
                    evento.preventDefault();
                    const paso = Math.max(1, Math.floor(this.$refs.cuerpo.clientHeight / 26) - 1);
                    this.mover(tecla === 'PageDown' ? paso : -paso, 0, evento.shiftKey);
                    return;
                }
                case 'Home':
                    evento.preventDefault();
                    this.seleccionar(ctrl ? 0 : ctx.activa.fila, 0, evento.shiftKey);
                    return;
                case 'Escape':
                    this.menu.abierto = false;
                    ctx.portapapeles = null;
                    return;
                default:
                    break;
            }
            if (!ctrl && !evento.altKey && (tecla.length === 1 || tecla === 'Dead')) {
                if (tecla !== 'Dead') {
                    evento.preventDefault();
                }
                this.abrirEditor(tecla === 'Dead' ? '' : tecla, true);
            }
        },

        /** Ctrl + tecla. Devuelve true si la atendió. */
        atajo(tecla, evento) {
            const acciones = {
                z: () => (evento.shiftKey ? this.rehacerCambio() : this.deshacerCambio()),
                y: () => this.rehacerCambio(),
                b: () => this.alternarEstilo('n'),
                n: () => this.alternarEstilo('n'),
                i: () => this.alternarEstilo('k'),
                k: () => this.alternarEstilo('k'),
                u: () => this.alternarEstilo('s'),
                s: () => this.alternarEstilo('s'),
                a: () => {
                    ctx.activa = { fila: 0, col: 0 };
                    ctx.extremo = { fila: Math.max(0, ctx.motor.filas.length - 1), col: ctx.motor.columnas.length - 1 };
                    this.refrescarSeleccion();
                },
            };
            if (!acciones[tecla]) {
                return false;
            }
            acciones[tecla]();
            return true;
        },

        /** Ctrl+flecha: hasta la última celda con datos de ese lado (o el borde). */
        saltarAlBorde(df, dc, extender) {
            const desde = extender ? ctx.extremo : ctx.activa;
            const lleno = (f, c) => {
                const v = ctx.motor.crudo(f, c);
                return v !== null && v !== '';
            };
            let { fila, col } = desde;
            const maxFila = Math.max(0, ctx.motor.filas.length - 1);
            const maxCol = ctx.motor.columnas.length - 1;
            const dentro = (f, c) => f >= 0 && c >= 0 && f <= maxFila && c <= maxCol;
            const siguienteLleno = dentro(fila + df, col + dc) && lleno(fila + df, col + dc);
            if (lleno(fila, col) && siguienteLleno) {
                while (dentro(fila + df, col + dc) && lleno(fila + df, col + dc)) {
                    fila += df;
                    col += dc;
                }
            } else {
                fila += df;
                col += dc;
                while (dentro(fila, col) && !lleno(fila, col) && dentro(fila + df, col + dc)) {
                    fila += df;
                    col += dc;
                }
            }
            this.seleccionar(fila, col, extender);
        },

        // ------------------------------------------------------------ ratón

        alPresionar(evento) {
            if (evento.button !== 0 || evento.target.closest('a')) {
                return;
            }
            this.menu.abierto = false;
            if (ctx.editando) {
                this.confirmarEdicion(0, 0);
            }
            const { fila, col } = ctx.rejilla.celdaDesdeEvento(evento);
            this.seleccionar(fila, col, evento.shiftKey);
            ctx.arrastrando = { tipo: 'celdas' };
            this.$refs.rejilla.focus({ preventScroll: true });
            evento.preventDefault();
        },

        alPresionarEncabezado(evento) {
            const asa = evento.target.closest('[data-asa]');
            if (asa) {
                const col = Number(asa.dataset.asa);
                ctx.arrastrando = { tipo: 'ancho', col, x: evento.clientX, ancho: ctx.rejilla.anchoColumna(col) };
                evento.preventDefault();
                return;
            }
            const nodo = evento.target.closest('[data-col]');
            if (!nodo || evento.button !== 0) {
                return;
            }
            this.seleccionarColumna(Number(nodo.dataset.col), evento.shiftKey);
            ctx.arrastrando = { tipo: 'columnas' };
            this.$refs.rejilla.focus({ preventScroll: true });
            evento.preventDefault();
        },

        alPresionarNumero(evento) {
            const nodo = evento.target.closest('[data-fila]');
            if (!nodo || evento.button !== 0) {
                return;
            }
            this.seleccionarFila(Number(nodo.dataset.fila), evento.shiftKey);
            ctx.arrastrando = { tipo: 'filas' };
            this.$refs.rejilla.focus({ preventScroll: true });
            evento.preventDefault();
        },

        seleccionarColumna(col, extender = false) {
            if (!extender) {
                ctx.activa = { fila: 0, col };
            }
            ctx.extremo = { fila: Math.max(0, ctx.motor.filas.length - 1), col };
            ctx.activa = { ...ctx.activa, fila: 0 };
            this.refrescarSeleccion();
        },

        seleccionarFila(fila, extender = false) {
            if (!extender) {
                ctx.activa = { fila, col: 0 };
            }
            ctx.extremo = { fila, col: ctx.motor.columnas.length - 1 };
            ctx.activa = { ...ctx.activa, col: 0 };
            this.refrescarSeleccion();
        },

        seleccionarTodo() {
            this.atajo('a', {});
            this.$refs.rejilla.focus({ preventScroll: true });
        },

        moverArrastre(evento) {
            const arrastre = ctx.arrastrando;
            if (!arrastre) {
                return;
            }
            if (arrastre.tipo === 'ancho') {
                const ancho = Math.max(40, Math.min(800, arrastre.ancho + evento.clientX - arrastre.x));
                ctx.motor.columnas[arrastre.col].ancho = Math.round(ancho);
                ctx.rejilla.recalcularGeometria();
                this.refrescarSeleccion();
                return;
            }
            const { fila, col } = ctx.rejilla.celdaDesdeEvento(evento);
            if (arrastre.tipo === 'columnas') {
                ctx.extremo = { fila: Math.max(0, ctx.motor.filas.length - 1), col };
            } else if (arrastre.tipo === 'filas') {
                ctx.extremo = { fila, col: ctx.motor.columnas.length - 1 };
            } else {
                ctx.extremo = { fila, col };
            }
            ctx.rejilla.asegurarVisible(ctx.extremo.fila, ctx.extremo.col);
            this.refrescarSeleccion();
        },

        terminarArrastre() {
            const arrastre = ctx.arrastrando;
            ctx.arrastrando = null;
            if (arrastre?.tipo === 'ancho') {
                this.guardarAncho(arrastre.col);
            }
        },

        abrirMenu(evento, tipo) {
            evento.preventDefault();
            if (tipo === 'celda') {
                const { fila, col } = ctx.rejilla.celdaDesdeEvento(evento);
                const r = this.rango();
                if (fila < r.f1 || fila > r.f2 || col < r.c1 || col > r.c2) {
                    this.seleccionar(fila, col);
                }
            } else if (tipo === 'columna') {
                const nodo = evento.target.closest('[data-col]');
                const r = this.rango();
                const col = Number(nodo?.dataset.col ?? ctx.activa.col);
                if (col < r.c1 || col > r.c2) {
                    this.seleccionarColumna(col);
                }
            } else if (tipo === 'fila') {
                const nodo = evento.target.closest('[data-fila]');
                const r = this.rango();
                const fila = Number(nodo?.dataset.fila ?? ctx.activa.fila);
                if (fila < r.f1 || fila > r.f2) {
                    this.seleccionarFila(fila);
                }
            }
            const caja = this.$root.getBoundingClientRect();
            this.menu = { abierto: true, x: evento.clientX - caja.left, y: evento.clientY - caja.top, tipo };
        },

        // ------------------------------------------------------------ edición

        /**
         * @param {string|null} inicial  texto con el que empieza (null = el de la celda)
         * @param {boolean} ingresando  true = se empezó escribiendo: las flechas confirman y se mueven
         */
        abrirEditor(inicial, ingresando) {
            const { fila, col } = ctx.activa;
            const columna = ctx.motor.columnas[col];
            if (!this.editable() || !columna) {
                if (!this.permisos.editar) {
                    this.avisar('Solo lectura: no puedes modificar esta hoja.');
                }
                return;
            }
            if (columna.tipo === 'foto') {
                this.avisar('Las fotos se cambian desde «Abrir registro» (clic derecho en la fila).');
                return;
            }
            const original = ctx.motor.textoEditable(fila, col);
            ctx.editando = { fila, col, original, ingresando };
            this.editorTipo = ['lista', 'booleano'].includes(columna.tipo) && inicial === null ? 'opciones' : 'texto';
            this.opcionesEditor = columna.tipo === 'booleano' ? ['Sí', 'No'] : columna.opciones;
            this.editorVisible = true;

            const combinada = ctx.motor.combinacionEn(fila, col);
            const caja = ctx.rejilla.caja(combinada ?? { f1: fila, c1: col, f2: fila, c2: col });
            this.$nextTick(() => {
                const editor = this.editorTipo === 'opciones' ? this.$refs.selector : this.$refs.editor;
                Object.assign(editor.style, {
                    left: `${caja.left}px`, top: `${caja.top}px`, minWidth: `${caja.width}px`, minHeight: `${caja.height}px`,
                });
                editor.value = inicial ?? original;
                editor.focus();
                if (this.editorTipo === 'texto') {
                    editor.setSelectionRange(editor.value.length, editor.value.length);
                }
            });
        },

        tecladoEditor(evento) {
            const edicion = ctx.editando;
            if (evento.key === 'Escape') {
                evento.preventDefault();
                this.cancelarEdicion();
                return;
            }
            if (evento.key === 'Enter' && !evento.altKey) {
                evento.preventDefault();
                this.confirmarEdicion(evento.shiftKey ? -1 : 1, 0);
                return;
            }
            if (evento.key === 'Enter' && evento.altKey && this.editorTipo === 'texto') {
                evento.preventDefault();
                const editor = this.$refs.editor;
                const inicio = editor.selectionStart;
                editor.setRangeText('\n', inicio, editor.selectionEnd, 'end');
                return;
            }
            if (evento.key === 'Tab') {
                evento.preventDefault();
                this.confirmarEdicion(0, evento.shiftKey ? -1 : 1);
                return;
            }
            const flechas = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };
            if (edicion.ingresando && flechas[evento.key] && this.editorTipo === 'texto') {
                evento.preventDefault();
                this.confirmarEdicion(...flechas[evento.key]);
            }
        },

        async confirmarEdicion(dFila, dCol) {
            const edicion = ctx.editando;
            if (!edicion) {
                return;
            }
            const editor = this.editorTipo === 'opciones' ? this.$refs.selector : this.$refs.editor;
            const valor = editor.value;
            this.cerrarEditor();
            this.mover(dFila, dCol);
            if (valor !== edicion.original) {
                await this.aplicarCambios([{ fila: edicion.fila, col: edicion.col, valor }]);
            }
        },

        cancelarEdicion() {
            this.cerrarEditor();
        },

        cerrarEditor() {
            ctx.editando = null;
            this.editorVisible = false;
            this.$refs.rejilla.focus({ preventScroll: true });
        },

        enfocarBarra() {
            if (!this.editable()) {
                return;
            }
            this.editandoBarra = true;
        },

        async confirmarBarra(evento) {
            if (evento?.key === 'Escape') {
                this.editandoBarra = false;
                this.actualizarBarra();
                this.$refs.rejilla.focus();
                return;
            }
            const { fila, col } = ctx.activa;
            const valor = this.textoBarra;
            this.editandoBarra = false;
            this.$refs.rejilla.focus();
            if (this.editable() && valor !== ctx.motor.textoEditable(fila, col)) {
                await this.aplicarCambios([{ fila, col, valor }]);
            }
            if (evento?.key === 'Enter') {
                this.mover(1, 0);
            }
        },

        /**
         * Aplica cambios de celda (por posición): crea las filas que falten al
         * final, actualiza la pantalla al instante, los manda al servidor y
         * los registra para deshacer.
         *
         * @param {{fila: number, col: number, valor?: any, formato?: Object|null}[]} cambios
         */
        async aplicarCambios(cambios, registrar = true) {
            if (!this.editable()) {
                return;
            }
            const admitidos = cambios.filter((c) => !('valor' in c) || valorAdmitido(ctx.motor.columnas[c.col], c.valor));
            const rechazados = cambios.length - admitidos.length;
            if (rechazados > 0) {
                this.avisar(rechazados === 1
                    ? `«${ctx.motor.columnas[cambios.find((c) => !admitidos.includes(c)).col].nombre}» no acepta ese valor por su tipo de columna.`
                    : `${rechazados} celdas no se cambiaron porque el valor no corresponde al tipo de su columna.`, true);
            }
            if (admitidos.length === 0) {
                return;
            }
            const ultima = Math.max(...admitidos.map((c) => c.fila));
            if (ultima >= ctx.motor.filas.length && !(await this.crearFilasHasta(ultima))) {
                return;
            }

            const registro = [];
            for (const cambio of admitidos) {
                const fila = ctx.motor.filas[cambio.fila];
                const columna = ctx.motor.columnas[cambio.col];
                const antes = {};
                if ('valor' in cambio) {
                    antes.valor = ctx.motor.crudo(cambio.fila, cambio.col);
                }
                if ('formato' in cambio) {
                    antes.formato = ctx.motor.estilo(cambio.fila, cambio.col);
                }
                const despues = {};
                if ('valor' in cambio) {
                    despues.valor = cambio.valor;
                }
                if ('formato' in cambio) {
                    despues.formato = cambio.formato;
                }
                registro.push({ filaId: fila.id, clave: columna.clave, antes, despues });
                ctx.motor.poner(cambio.fila, cambio.col, despues);
            }
            ctx.api.guardarCeldas(registro.map((r) => ({ fila: r.filaId, columna: r.clave, ...r.despues })));
            if (registrar) {
                ctx.deshacer.push({ tipo: 'celdas', cambios: registro });
                ctx.rehacer = [];
                this.actualizarPilas();
            }
            ctx.rejilla.programar();
            this.refrescarSeleccion();
        },

        /** Crea filas vacías al final hasta que exista la posición $ultima. */
        async crearFilasHasta(ultima) {
            try {
                while (ctx.motor.filas.length <= ultima) {
                    const faltan = Math.min(config.maxFilasLote, ultima - ctx.motor.filas.length + 1);
                    const { ids } = await ctx.api.insertarFilas(null, faltan);
                    ids.forEach((id) => ctx.motor.filas.push({ id, datos: {}, formato: {}, estacion: null }));
                }
                ctx.rejilla.recalcularGeometria();
                return true;
            } catch (error) {
                this.avisar(`No se pudieron agregar filas: ${error.message}`, true);
                return false;
            }
        },

        vaciarSeleccion() {
            const r = this.rangoReal();
            const cambios = [];
            for (let f = r.f1; f <= r.f2; f++) {
                for (let c = r.c1; c <= r.c2; c++) {
                    if (ctx.motor.crudo(f, c) !== null && ctx.motor.columnas[c].tipo !== 'foto') {
                        cambios.push({ fila: f, col: c, valor: null });
                    }
                }
            }
            if (cambios.length) {
                this.aplicarCambios(cambios);
            }
        },

        // ------------------------------------------------------------ portapapeles

        escucharPortapapeles() {
            const activo = () => this.$refs.rejilla === document.activeElement && !ctx.editando;
            document.addEventListener('copy', (e) => {
                if (activo()) {
                    this.copiar(e, false);
                }
            });
            document.addEventListener('cut', (e) => {
                if (activo()) {
                    this.copiar(e, this.editable());
                }
            });
            document.addEventListener('paste', (e) => {
                if (activo()) {
                    e.preventDefault();
                    this.pegar(e.clipboardData?.getData('text/plain') ?? '');
                }
            });
        },

        copiar(evento, cortar) {
            const r = this.rangoReal();
            if (r.f2 < r.f1) {
                return;
            }
            const textos = [];
            const celdas = [];
            for (let f = r.f1; f <= r.f2; f++) {
                const filaTextos = [];
                const filaCeldas = [];
                for (let c = r.c1; c <= r.c2; c++) {
                    filaTextos.push(ctx.motor.mostrar(f, c).texto);
                    filaCeldas.push({ crudo: ctx.motor.crudo(f, c), texto: ctx.motor.textoEditable(f, c), formato: ctx.motor.estilo(f, c) });
                }
                textos.push(filaTextos);
                celdas.push(filaCeldas);
            }
            const texto = aTsv(textos);
            evento.clipboardData.setData('text/plain', texto);
            evento.preventDefault();
            ctx.portapapeles = { texto, celdas, origen: r, cortar };
            this.avisar(cortar ? 'Cortado: pégalo donde quieras moverlo.' : 'Copiado.');
        },

        async pegar(texto) {
            if (!this.editable() || texto === '') {
                return;
            }
            const interno = ctx.portapapeles && ctx.portapapeles.texto.replace(/\r\n?/g, '\n') === texto.replace(/\r\n?/g, '\n') ? ctx.portapapeles : null;
            const bloque = interno ? interno.celdas : deTsv(texto).map((fila) => fila.map((t) => ({ crudo: t, texto: t, formato: undefined })));
            const destino = this.rango();
            const alto = bloque.length;
            const ancho = Math.max(...bloque.map((f) => f.length));
            // Un bloque que cabe un número exacto de veces en la selección la llena (como Excel).
            const repetirF = (destino.f2 - destino.f1 + 1) % alto === 0 ? (destino.f2 - destino.f1 + 1) / alto : 1;
            const repetirC = (destino.c2 - destino.c1 + 1) % ancho === 0 ? (destino.c2 - destino.c1 + 1) / ancho : 1;
            const cambios = [];
            let fuera = 0;
            for (let i = 0; i < alto * repetirF; i++) {
                for (let j = 0; j < ancho * repetirC; j++) {
                    const fila = destino.f1 + i;
                    const col = destino.c1 + j;
                    const celda = bloque[i % alto][j % ancho];
                    if (col >= ctx.motor.columnas.length) {
                        fuera++;
                        continue;
                    }
                    if (!celda || ctx.motor.columnas[col].tipo === 'foto') {
                        continue;
                    }
                    const cambio = { fila, col, valor: this.valorPegado(celda, interno, fila, col) };
                    if (interno) {
                        cambio.formato = celda.formato ?? null;
                    }
                    cambios.push(cambio);
                }
            }
            if (interno?.cortar) {
                const o = interno.origen;
                for (let f = o.f1; f <= o.f2; f++) {
                    for (let c = o.c1; c <= o.c2; c++) {
                        const pisada = cambios.some((x) => x.fila === f && x.col === c);
                        if (!pisada && ctx.motor.crudo(f, c) !== null) {
                            cambios.push({ fila: f, col: c, valor: null, formato: null });
                        }
                    }
                }
                ctx.portapapeles = null;
            }
            if (fuera > 0) {
                this.avisar(`${fuera} celdas quedaron fuera de la hoja (no hay más columnas).`, true);
            }
            await this.aplicarCambios(cambios);
            ctx.extremo = { fila: destino.f1 + alto * repetirF - 1, col: Math.min(ctx.motor.columnas.length - 1, destino.c1 + ancho * repetirC - 1) };
            this.refrescarSeleccion();
        },

        valorPegado(celda, interno, fila, col) {
            if (!interno) {
                return celda.texto;
            }
            if (esFormula(celda.crudo)) {
                const i = fila - interno.origen.f1;
                const j = col - interno.origen.c1;
                const origenF = interno.origen.f1 + (i % interno.celdas.length);
                const origenC = interno.origen.c1 + (j % interno.celdas[0].length);
                return interno.cortar ? celda.crudo : trasladar(celda.crudo, fila - origenF, col - origenC);
            }
            return celda.texto === '' ? null : celda.texto;
        },

        letra(col) {
            return letrasColumna(col);
        },
    };
}
