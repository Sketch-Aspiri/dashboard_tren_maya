/**
 * Peticiones de la hoja de cálculo a sus endpoints JSON internos (sesión +
 * CSRF, ver .claude/rules/api-conventions.md). Todas pasan por una cola:
 * una a la vez y en orden, para que un "deshacer" o un pegado nunca llegue
 * antes que la edición que lo precede. Los cambios de celda se agrupan unos
 * milisegundos y se mandan por lotes.
 */
import { mensajesDeError, tokenCsrf } from '../secciones';

const ESPERA_LOTE_MS = 350;

export class ErrorPeticion extends Error {
    /** @param {string[]} mensajes @param {number} estado */
    constructor(mensajes, estado) {
        super(mensajes.join(' '));
        this.mensajes = mensajes;
        this.estado = estado;
    }
}

export class ApiHoja {
    /**
     * @param {{urls: Object<string, string>, tabla: number, maxCeldasLote: number}} opciones
     * @param {{alGuardar: (filas: Object) => void, alFallar: (error: ErrorPeticion, cambios: Array) => void, alCambiarEstado: (pendientes: number) => void}} eventos
     */
    constructor(opciones, eventos) {
        this.urls = opciones.urls;
        this.tabla = opciones.tabla;
        this.maxCeldasLote = opciones.maxCeldasLote;
        this.eventos = eventos;
        this.cola = Promise.resolve();
        this.pendientes = new Map();
        this.temporizador = null;
        this.enVuelo = 0;
    }

    async pedir(metodo, url, cuerpo = null) {
        const respuesta = await fetch(url, {
            method: metodo,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': tokenCsrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: cuerpo === null ? undefined : JSON.stringify(cuerpo),
        });
        const json = await respuesta.json().catch(() => null);
        if (!respuesta.ok || json?.success === false) {
            throw new ErrorPeticion(mensajesDeError(respuesta.status, json), respuesta.status);
        }
        return json?.data ?? null;
    }

    /** Encola una operación; devuelve su promesa. */
    encolar(operacion) {
        this.enVuelo++;
        this.eventos.alCambiarEstado(this.totalPendiente());
        const resultado = this.cola.then(operacion);
        this.cola = resultado.catch(() => null).finally(() => {
            this.enVuelo--;
            this.eventos.alCambiarEstado(this.totalPendiente());
        });
        return resultado;
    }

    totalPendiente() {
        return this.pendientes.size + this.enVuelo;
    }

    cargar() {
        const url = new URL(this.urls.hoja, window.location.origin);
        url.searchParams.set('tabla', String(this.tabla));
        return this.pedir('GET', url.toString());
    }

    /**
     * Agrega cambios de celda al próximo lote. Uno posterior sobre la misma
     * celda se combina con el anterior (gana lo último).
     *
     * @param {{fila: number, columna: string, valor?: any, formato?: Object|null}[]} cambios
     */
    guardarCeldas(cambios) {
        for (const cambio of cambios) {
            const clave = `${cambio.fila}|${cambio.columna}`;
            this.pendientes.set(clave, { ...(this.pendientes.get(clave) ?? {}), ...cambio });
        }
        this.eventos.alCambiarEstado(this.totalPendiente());
        clearTimeout(this.temporizador);
        this.temporizador = setTimeout(() => this.vaciar(), ESPERA_LOTE_MS);
    }

    /** Manda ya lo pendiente (antes de un cambio de estructura, p. ej.). */
    vaciar() {
        clearTimeout(this.temporizador);
        if (this.pendientes.size === 0) {
            return this.cola;
        }
        const cambios = [...this.pendientes.values()];
        this.pendientes.clear();
        const lotes = [];
        for (let i = 0; i < cambios.length; i += this.maxCeldasLote) {
            lotes.push(cambios.slice(i, i + this.maxCeldasLote));
        }
        return Promise.all(lotes.map((lote) => this.encolar(async () => {
            try {
                const datos = await this.pedir('POST', this.urls.celdas, { tabla: this.tabla, cambios: lote });
                this.eventos.alGuardar(datos.filas, lote);
            } catch (error) {
                this.eventos.alFallar(error instanceof ErrorPeticion ? error : new ErrorPeticion(['Revisa tu conexión e intenta de nuevo.'], 0), lote);
            }
        })));
    }

    /** Operación de estructura: primero se mandan las celdas pendientes. */
    async estructura(metodo, url, cuerpo = {}) {
        await this.vaciar();
        return this.encolar(() => this.pedir(metodo, url, { tabla: this.tabla, ...cuerpo }));
    }

    insertarFilas(antesDe, cantidad) {
        return this.estructura('POST', this.urls.insertarFilas, { antes_de: antesDe, cantidad });
    }

    eliminarFilas(ids) {
        return this.estructura('POST', this.urls.eliminarFilas, { filas: ids });
    }

    insertarColumna(antesDe, nombre = null) {
        return this.estructura('POST', this.urls.insertarColumna, { antes_de: antesDe, nombre });
    }

    actualizarColumna(id, cambios) {
        return this.estructura('PUT', this.urls.columna.replace('__ID__', String(id)), cambios);
    }

    eliminarColumna(id) {
        return this.estructura('DELETE', this.urls.columna.replace('__ID__', String(id)));
    }

    guardarDiseno(diseno) {
        return this.estructura('PUT', this.urls.diseno, diseno);
    }

    ordenar(clave, direccion) {
        return this.estructura('POST', this.urls.ordenar, { columna: clave, direccion });
    }
}
