/**
 * Acciones de la barra de herramientas y del menú contextual de la hoja:
 * formato de celda, combinar, formato como tabla, estructura (filas,
 * columnas, ordenar) y deshacer/rehacer. Se mezclan en el componente de
 * ./componente.js; `ctx` es su estado fuera de Alpine.
 *
 * Deshacer cubre celdas (valor y formato) y el diseño. Los cambios de
 * estructura recargan la hoja desde el servidor (que ya ajustó fórmulas y
 * combinaciones) y vacían el historial.
 */
import { esFormula, nombreCelda } from './formulas';
import { ESTILOS_TABLA } from './motor';

export const PALETA = [
    '#000000', '#434343', '#666666', '#999999', '#d9d9d9', '#ffffff',
    '#0f5132', '#198754', '#1e3a8a', '#2563eb', '#0e7490', '#7c3aed',
    '#b91c1c', '#ea580c', '#ca8a04', '#fde68a', '#bbf7d0', '#bfdbfe',
    '#fecaca', '#fed7aa', '#e9d5ff', '#f3f4f6',
];

export const TAMANOS = [9, 10, 11, 12, 14, 16, 18, 20, 24];

export function crearAcciones(ctx) {
    return {
        paleta: PALETA,
        tamanos: TAMANOS,
        estilosTabla: Object.entries(ESTILOS_TABLA).map(([clave, e]) => ({ clave, ...e })),

        // ------------------------------------------------------------ formato

        alternarEstilo(clave) {
            this.ponerEstilo(clave, !this.estiloActivo[clave]);
        },

        /** Aplica una propiedad de estilo a toda la selección (null/false = quitarla). */
        ponerEstilo(clave, valor) {
            const r = this.rangoReal();
            const cambios = [];
            for (let f = r.f1; f <= r.f2; f++) {
                for (let c = r.c1; c <= r.c2; c++) {
                    const actual = { ...(ctx.motor.estilo(f, c) ?? {}) };
                    if (valor === null || valor === false || valor === '') {
                        delete actual[clave];
                    } else {
                        actual[clave] = valor;
                    }
                    cambios.push({ fila: f, col: c, formato: Object.keys(actual).length ? actual : null });
                }
            }
            this.aplicarCambios(cambios);
            this.menu.abierto = false;
        },

        borrarFormato() {
            const r = this.rangoReal();
            const cambios = [];
            for (let f = r.f1; f <= r.f2; f++) {
                for (let c = r.c1; c <= r.c2; c++) {
                    if (ctx.motor.estilo(f, c)) {
                        cambios.push({ fila: f, col: c, formato: null });
                    }
                }
            }
            this.aplicarCambios(cambios);
            this.menu.abierto = false;
        },

        /** Σ: suma de la columna por encima de la celda activa. */
        autosuma() {
            const { fila, col } = ctx.activa;
            if (fila === 0) {
                this.avisar('Pon la autosuma debajo de los números que quieres sumar.');
                return;
            }
            this.aplicarCambios([{ fila, col, valor: `=SUMA(${nombreCelda(0, col)}:${nombreCelda(fila - 1, col)})` }]);
        },

        // ------------------------------------------------------------ diseño

        async combinar() {
            const r = this.rangoReal();
            if (r.f1 === r.f2 && r.c1 === r.c2) {
                this.avisar('Elige más de una celda para combinar.');
                return;
            }
            const sinOcultos = this.celdasConValorFueraDeLaPrimera(r);
            if (sinOcultos > 0 && !(await this.confirmar('Combinar celdas', `Se verá solo el valor de ${nombreCelda(r.f1, r.c1)}; los otros ${sinOcultos} valores se conservan pero quedan ocultos.`))) {
                return;
            }
            const combinaciones = ctx.motor.diseno.combinaciones.filter((m) => !this.seTocan(m, r));
            await this.guardarDiseno({ combinaciones: [...combinaciones, r], tablas: ctx.motor.diseno.tablas });
        },

        async separar() {
            const r = this.rango();
            await this.guardarDiseno({
                combinaciones: ctx.motor.diseno.combinaciones.filter((m) => !this.seTocan(m, r)),
                tablas: ctx.motor.diseno.tablas,
            });
        },

        async formatoTabla(estilo) {
            const r = this.rangoReal();
            if (r.f2 <= r.f1) {
                this.avisar('Elige al menos dos filas (la primera será el encabezado).');
                return;
            }
            const tablas = ctx.motor.diseno.tablas.filter((t) => !this.seTocan(t, r));
            await this.guardarDiseno({
                combinaciones: ctx.motor.diseno.combinaciones,
                tablas: [...tablas, { ...r, estilo, encabezado: true, bandas: true }],
            });
        },

        async quitarTabla() {
            const r = this.rango();
            await this.guardarDiseno({
                combinaciones: ctx.motor.diseno.combinaciones,
                tablas: ctx.motor.diseno.tablas.filter((t) => !this.seTocan(t, r)),
            });
        },

        seTocan(a, b) {
            return a.f1 <= b.f2 && b.f1 <= a.f2 && a.c1 <= b.c2 && b.c1 <= a.c2;
        },

        celdasConValorFueraDeLaPrimera(r) {
            let total = 0;
            for (let f = r.f1; f <= r.f2; f++) {
                for (let c = r.c1; c <= r.c2; c++) {
                    if ((f !== r.f1 || c !== r.c1) && ctx.motor.crudo(f, c) !== null) {
                        total++;
                    }
                }
            }
            return total;
        },

        async guardarDiseno(nuevo, registrar = true) {
            this.menu.abierto = false;
            if (!this.editable()) {
                return;
            }
            const antes = { combinaciones: [...ctx.motor.diseno.combinaciones], tablas: [...ctx.motor.diseno.tablas] };
            try {
                const { diseno } = await ctx.api.guardarDiseno(nuevo);
                ctx.motor.diseno = { combinaciones: [...diseno.combinaciones], tablas: [...diseno.tablas] };
                ctx.motor.indexarDiseno();
                if (registrar) {
                    ctx.deshacer.push({ tipo: 'diseno', antes, despues: diseno });
                    ctx.rehacer = [];
                    this.actualizarPilas();
                }
                this.seleccionar(ctx.activa.fila, ctx.activa.col);
            } catch (error) {
                this.avisar(error.message, true);
            }
        },

        // ------------------------------------------------------------ estructura

        async estructura(operacion, mensajeError) {
            this.menu.abierto = false;
            try {
                await operacion();
                ctx.deshacer = [];
                ctx.rehacer = [];
                this.actualizarPilas();
                await this.recargar();
            } catch (error) {
                this.avisar(`${mensajeError}: ${error.message}`, true);
            }
        },

        insertarFilas(debajo) {
            const r = this.rango();
            const cantidad = Math.min(ctx.config.maxFilasLote, r.f2 - r.f1 + 1);
            const referencia = debajo ? r.f2 + 1 : r.f1;
            if (referencia >= ctx.motor.filas.length) {
                return this.crearFilasHasta(referencia + cantidad - 1).then(() => this.refrescarSeleccion());
            }
            return this.estructura(() => ctx.api.insertarFilas(ctx.motor.filas[referencia].id, cantidad), 'No se insertaron las filas');
        },

        async eliminarFilas() {
            const r = this.rangoReal();
            const ids = ctx.motor.filas.slice(r.f1, r.f2 + 1).map((fila) => fila.id);
            if (ids.length === 0) {
                return;
            }
            if (ids.length > ctx.config.maxFilasLote) {
                this.avisar(`Elimina como máximo ${ctx.config.maxFilasLote} filas a la vez.`, true);
                return;
            }
            const texto = ids.length === 1 ? `¿Eliminar la fila ${r.f1 + 1}?` : `¿Eliminar las filas ${r.f1 + 1} a ${r.f2 + 1} (${ids.length})?`;
            if (await this.confirmar('Eliminar filas', `${texto} Esto no se puede deshacer desde la hoja.`, { peligro: true })) {
                await this.estructura(() => ctx.api.eliminarFilas(ids), 'No se eliminaron las filas');
            }
        },

        insertarColumna(derecha) {
            const r = this.rango();
            const siguiente = ctx.motor.columnas[derecha ? r.c2 + 1 : r.c1];
            return this.estructura(() => ctx.api.insertarColumna(siguiente?.clave ?? null), 'No se insertó la columna');
        },

        async eliminarColumna() {
            const columna = ctx.motor.columnas[ctx.activa.col];
            if (!columna) {
                return;
            }
            if (await this.confirmar('Eliminar columna', `¿Eliminar la columna «${columna.nombre}» y todos sus valores?`, { peligro: true })) {
                await this.estructura(() => ctx.api.eliminarColumna(columna.id), 'No se eliminó la columna');
            }
        },

        async renombrarColumna() {
            const columna = ctx.motor.columnas[ctx.activa.col];
            if (!columna || !this.permisos.estructura) {
                return;
            }
            const nombre = await this.confirmar('Renombrar columna', `Nombre de la columna ${this.letra(ctx.activa.col)}:`, { campo: columna.nombre });
            if (nombre && nombre.trim() !== '' && nombre !== columna.nombre) {
                await this.estructura(() => ctx.api.actualizarColumna(columna.id, { nombre: nombre.trim() }), 'No se renombró la columna');
            }
        },

        async ordenar(direccion) {
            const columna = ctx.motor.columnas[ctx.activa.col];
            if (!columna) {
                return;
            }
            const conFormulas = ctx.motor.filas.some((fila) => Object.values(fila.datos).some(esFormula));
            if (conFormulas && !(await this.confirmar('Ordenar la hoja', 'La hoja tiene fórmulas: después de ordenar seguirán apuntando a las mismas celdas (como en Excel). ¿Ordenar?'))) {
                return;
            }
            await this.estructura(() => ctx.api.ordenar(columna.clave, direccion), 'No se ordenó la hoja');
        },

        async guardarAncho(col) {
            const columna = ctx.motor.columnas[col];
            if (!columna || !this.permisos.estructura) {
                return;
            }
            try {
                await ctx.api.actualizarColumna(columna.id, { ancho: columna.ancho });
            } catch (error) {
                this.avisar(`No se guardó el ancho: ${error.message}`, true);
            }
        },

        /** Formulario completo del registro (fotos, estación, adjuntos). */
        abrirRegistro() {
            this.menu.abierto = false;
            const fila = ctx.motor.filas[ctx.activa.fila];
            if (!fila || !ctx.config.puedeAbrirRegistro) {
                return;
            }
            const valores = {};
            for (const columna of ctx.motor.columnas) {
                const v = fila.datos[columna.clave];
                valores[columna.clave] = typeof v === 'boolean' ? (v ? '1' : '0') : (v ?? null);
            }
            window.dispatchEvent(new CustomEvent('fila-editar', { detail: { id: fila.id, valores, estacion: fila.estacion_id ?? null } }));
        },

        // ------------------------------------------------------------ deshacer

        actualizarPilas() {
            this.puedeDeshacer = ctx.deshacer.length > 0;
            this.puedeRehacer = ctx.rehacer.length > 0;
        },

        deshacerCambio() {
            this.recorrerHistorial(ctx.deshacer, ctx.rehacer, 'antes');
        },

        rehacerCambio() {
            this.recorrerHistorial(ctx.rehacer, ctx.deshacer, 'despues');
        },

        async recorrerHistorial(desde, hacia, lado) {
            const paso = desde.pop();
            if (!paso || !this.editable()) {
                return;
            }
            hacia.push(paso);
            this.actualizarPilas();
            if (paso.tipo === 'diseno') {
                await this.guardarDiseno(paso[lado], false);
                return;
            }
            const cambios = [];
            for (const cambio of paso.cambios) {
                const fila = ctx.motor.posicionDeFila(cambio.filaId);
                const col = ctx.motor.columnas.findIndex((c) => c.clave === cambio.clave);
                if (fila >= 0 && col >= 0) {
                    cambios.push({ fila, col, ...cambio[lado] });
                }
            }
            if (cambios.length) {
                const primero = cambios[0];
                this.seleccionar(primero.fila, primero.col);
                await this.aplicarCambios(cambios, false);
            }
        },

        // ------------------------------------------------------------ diálogo

        /**
         * Diálogo propio (no window.confirm/prompt). Resuelve true/false, o el
         * texto escrito si se pidió un campo.
         */
        confirmar(titulo, texto, { campo = null, peligro = false } = {}) {
            return new Promise((resolver) => {
                this.dialogo = { abierto: true, titulo, texto, valor: campo ?? '', conCampo: campo !== null, peligro, resolver };
                this.$nextTick(() => (campo !== null ? this.$refs.campoDialogo : this.$refs.aceptarDialogo)?.focus());
            });
        },

        cerrarDialogo(aceptado) {
            const { resolver, conCampo, valor } = this.dialogo;
            this.dialogo = { ...this.dialogo, abierto: false, resolver: null };
            resolver?.(aceptado ? (conCampo ? valor : true) : (conCampo ? null : false));
            this.$nextTick(() => this.$refs.rejilla.focus({ preventScroll: true }));
        },

        alternarPantallaCompleta() {
            this.pantallaCompleta = !this.pantallaCompleta;
            this.$nextTick(() => ctx.rejilla.programar());
        },
    };
}
