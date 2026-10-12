/**
 * Componentes Alpine de las secciones dinámicas (tablas + documentos +
 * cargas por arrastre). Se registran desde app.js con registrarSecciones().
 */

/**
 * Token CSRF del <meta name="csrf-token"> del layout, para las peticiones
 * AJAX internas (ver .claude/rules/api-conventions.md).
 *
 * @returns {string}
 */
export function tokenCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * Mensajes legibles de una respuesta de error JSON de Laravel (422 de un
 * Form Request, o el sobre {error: {message}} de los endpoints internos).
 *
 * @param {number} estado
 * @param {any} cuerpo
 * @returns {string[]}
 */
export function mensajesDeError(estado, cuerpo) {
    if (cuerpo?.errors) {
        return Object.values(cuerpo.errors).flat();
    }
    if (cuerpo?.error?.message) {
        return [cuerpo.error.message];
    }
    if (estado === 413) {
        return ['El archivo es más grande de lo que acepta el servidor.'];
    }
    if (estado === 429) {
        return ['Demasiadas peticiones seguidas. Espera un minuto e intenta de nuevo.'];
    }
    if (estado === 401 || estado === 419) {
        return ['Tu sesión expiró. Vuelve a iniciar sesión.'];
    }
    return [cuerpo?.message ?? 'No se pudo completar la operación. Intenta de nuevo.'];
}

/**
 * Zona de arrastrar y soltar (resources/views/components/zona-arrastre.blade.php).
 * Sube todos los archivos soltados en una sola petición con barra de
 * progreso; si el servidor detectó tabla en un único archivo, abre su
 * vista previa.
 *
 * @param {string} url
 */
function zonaArrastre(url) {
    return {
        arrastrando: false,
        subiendo: false,
        progreso: 0,
        mensaje: '',
        errores: [],
        resultados: [],
        soltar(evento) {
            this.arrastrando = false;
            this.subir(evento.dataTransfer?.files);
        },
        subir(archivos) {
            if (!archivos || archivos.length === 0 || this.subiendo) {
                return;
            }
            const datos = new FormData(this.$root);
            datos.delete('archivos[]');
            Array.from(archivos).forEach((archivo) => datos.append('archivos[]', archivo));

            this.subiendo = true;
            this.progreso = 0;
            this.errores = [];
            this.resultados = [];
            this.mensaje = 'Subiendo ' + archivos.length + ' archivo(s)…';

            const peticion = new XMLHttpRequest();
            peticion.open('POST', url);
            peticion.setRequestHeader('Accept', 'application/json');
            peticion.setRequestHeader('X-CSRF-TOKEN', tokenCsrf());
            peticion.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            peticion.upload.addEventListener('progress', (evento) => {
                if (evento.lengthComputable) {
                    this.progreso = Math.round((evento.loaded / evento.total) * 100);
                    if (this.progreso === 100) {
                        this.mensaje = 'Leyendo el contenido de los documentos…';
                    }
                }
            });
            peticion.addEventListener('load', () => this.alTerminar(peticion));
            peticion.addEventListener('error', () => {
                this.subiendo = false;
                this.errores = ['Se perdió la conexión mientras se subían los archivos.'];
            });
            peticion.send(datos);
        },
        alTerminar(peticion) {
            this.subiendo = false;
            let cuerpo = null;
            try {
                cuerpo = JSON.parse(peticion.responseText);
            } catch {
                cuerpo = null;
            }
            if (peticion.status >= 200 && peticion.status < 300 && cuerpo?.success) {
                if (cuerpo.data.redirigir) {
                    window.location.href = cuerpo.data.redirigir;
                    return;
                }
                this.resultados = cuerpo.data.archivos;
                if (this.$refs.selector) {
                    this.$refs.selector.value = '';
                }
                return;
            }
            this.errores = mensajesDeError(peticion.status, cuerpo);
        },
    };
}

/**
 * Ventana modal de alta/edición de un registro
 * (resources/views/secciones/partials/fila-modal.blade.php). La URL de
 * edición se arma desde una plantilla del servidor con el id numérico.
 *
 * @param {{urlNueva: string, urlEditar: string, reabrir: boolean, idAnterior: number|null, valoresAnteriores: Object}} opciones
 */
function filaFormulario(opciones) {
    return {
        abierta: false,
        id: null,
        url: opciones.urlNueva,
        valores: {},
        init() {
            if (opciones.reabrir) {
                this.abrir(opciones.idAnterior, { ...opciones.valoresAnteriores });
            }
        },
        nueva() {
            this.abrir(null, {});
        },
        editar(detalle) {
            this.abrir(detalle.id, { ...detalle.valores });
        },
        abrir(id, valores) {
            this.id = Number.isInteger(id) ? id : null;
            this.url = this.id ? opciones.urlEditar.replace('__ID__', String(this.id)) : opciones.urlNueva;
            this.valores = valores;
            this.abierta = true;
            this.$nextTick(() => this.$root.querySelector('form input:not([type=hidden]), form select, form textarea')?.focus());
        },
        cerrar() {
            this.abierta = false;
        },
    };
}

/**
 * Vista previa de una carga (resources/views/secciones/importacion.blade.php):
 * cada cambio de mapeo/modo/llave pide al servidor el resumen de cambios
 * (POST .../calcular), el mismo cálculo que se usa al aplicar.
 *
 * @param {Object} inicial
 */
function vistaPreviaImportacion(inicial) {
    return {
        ...inicial,
        mapeo: { ...inicial.mapeo },
        tipos: { ...inicial.tipos },
        llave: inicial.llave === null ? '' : String(inicial.llave),
        resumen: null,
        error: '',
        calculando: false,
        temporizador: null,
        nombreColumna(clave) {
            const existente = this.columnas.find((columna) => columna.clave === clave);
            return existente ? existente.nombre : clave;
        },
        calcular() {
            clearTimeout(this.temporizador);
            this.temporizador = setTimeout(() => this.pedirCalculo(), 250);
        },
        async pedirCalculo() {
            this.calculando = true;
            this.error = '';
            try {
                const respuesta = await fetch(this.urlCalcular, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': tokenCsrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        tabla: this.tabla,
                        fila_encabezado: this.filaEncabezado,
                        mapeo: this.mapeo,
                        tipos: this.tipos,
                        modo: this.modo,
                        llave: this.llave === '' ? null : Number(this.llave),
                    }),
                });
                const cuerpo = await respuesta.json().catch(() => null);
                if (respuesta.ok && cuerpo?.success) {
                    this.resumen = cuerpo.data;
                } else {
                    this.resumen = null;
                    this.error = mensajesDeError(respuesta.status, cuerpo).join(' ');
                }
            } catch {
                this.error = 'No se pudo calcular la vista previa. Revisa tu conexión.';
            } finally {
                this.calculando = false;
            }
        },
    };
}

/**
 * @param {import('alpinejs').Alpine} Alpine
 */
export function registrarSecciones(Alpine) {
    Alpine.data('zonaArrastre', zonaArrastre);
    Alpine.data('filaFormulario', filaFormulario);
    Alpine.data('vistaPreviaImportacion', vistaPreviaImportacion);
}
