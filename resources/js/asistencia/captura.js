import { armarOficio, lineasFuera, lineasVisitante } from './oficio';

/** Estatus que no llevan periodo en el oficio. */
const SIN_PERIODO = ['presente', 'descanso', 'dia_no_laborable'];

/** Accesos directos; los demás estatus se eligen de la lista. */
const RAPIDOS = [
    { valor: 'presente', texto: 'Presente' },
    { valor: 'descanso', texto: 'Descanso' },
    { valor: 'dia_no_laborable', texto: 'Día no lab.' },
];

const PISTAS_DETALLE = {
    comision: 'Ej. CORP. MÉRIDA',
    licencia_medica: 'Ej. folio de incapacidad',
};

const CAMPOS_VACIOS_FUERA = { empleado_id: '', coordinacion_destino: '', ubicacion_destino: '', fecha_inicio: '', fecha_fin: '', motivo: '', notas: '' };
const CAMPOS_VACIOS_VISITANTE = { nombre: '', no_trabajador: '', direccion_origen: '', fecha_inicio: '', fecha_fin: '', motivo: '', notas: '' };

const MENSAJE_ERROR = 'No se pudo guardar. Revisa tu conexión e inténtalo de nuevo.';

/**
 * Pantalla de captura de asistencia: los pasos, la edición en memoria y la
 * vista previa, que se recalcula con cada cambio aunque aún no se guarde.
 *
 * La asistencia se guarda con el botón (envío normal del formulario). Los
 * comisionados se guardan al momento por AJAX, para que agregar uno nunca
 * recargue la página ni pierda la asistencia sin guardar.
 */
export function capturaAsistencia(inicial) {
    return {
        ...inicial,
        rapidos: RAPIDOS,
        paso: inicial.pasoInicial,
        busqueda: '',
        soloPendientes: false,
        sucio: inicial.haySinGuardar,
        enviandoAsistencia: false,
        formFuera: null,
        formVisitante: null,
        confirmando: null,
        trayendo: false,
        aviso: '',
        resaltado: null,
        zoomHoja: 0.6,
        firmaUrl: null,

        iniciar() {
            const hoja = this.$refs.marcoHoja;

            if (hoja && 'ResizeObserver' in window) {
                new ResizeObserver(([entrada]) => {
                    if (entrada.contentRect.width > 0) {
                        this.zoomHoja = entrada.contentRect.width / 794;
                    }
                }).observe(hoja);
            }
        },

        // ------------------------------------------------- datos derivados

        get oficioArmado() {
            return armarOficio(this);
        },

        get pendientes() {
            return this.personas.filter((persona) => !persona.estatus).length;
        },

        get capturados() {
            return this.personas.length - this.pendientes;
        },

        get avance() {
            return this.personas.length === 0 ? 0 : Math.round((this.capturados / this.personas.length) * 100);
        },

        get totalComisionados() {
            return this.fuera.length + this.visitantes.length;
        },

        get continuidadesPendientes() {
            return this.personas.filter((persona) => this.puedeContinuar(persona));
        },

        /** Personas que aún pueden comisionarse (cada una una sola vez por día). */
        get personasDisponibles() {
            const editando = this.formFuera?.id ? this.formFuera.empleado_id : null;
            const ocupadas = new Set(this.fuera.map((comision) => comision.empleado_id));

            return this.personas.filter((persona) => !ocupadas.has(persona.id) || persona.id === Number(editando));
        },

        get fueraAnterioresDisponibles() {
            const ocupadas = new Set(this.fuera.map((comision) => comision.empleado_id));

            return this.anterior.fuera.filter((comision) => !ocupadas.has(comision.empleado_id));
        },

        get visitantesAnterioresDisponibles() {
            const presentes = new Set(this.visitantes.map((visitante) => visitante.nombre.trim().toUpperCase()));

            return this.anterior.visitantes.filter((visitante) => !presentes.has(visitante.nombre.trim().toUpperCase()));
        },

        persona(id) {
            return this.personas.find((persona) => persona.id === Number(id));
        },

        comisionDe(persona) {
            return this.fuera.find((comision) => comision.empleado_id === persona.id);
        },

        seccionDe(persona) {
            return persona?.grupo === 'eventuales' ? 'E.c' : 'E.b';
        },

        visible(persona) {
            if (this.soloPendientes && persona.estatus) {
                return false;
            }
            const termino = this.busqueda.trim().toLowerCase();

            return termino === '' || `${persona.nombre} ${persona.puesto} ${persona.no_empleado}`.toLowerCase().includes(termino);
        },

        usaPeriodo(persona) {
            return Boolean(persona.estatus) && !SIN_PERIODO.includes(persona.estatus);
        },

        pistaDetalle(persona) {
            return PISTAS_DETALLE[persona.estatus] ?? 'Opcional';
        },

        puedeContinuar(persona) {
            return Boolean(persona.continuidad) && !persona.estatus;
        },

        errorDe(persona, campo) {
            return this.erroresRegistros?.[`registros.${persona.id}.${campo}`] ?? '';
        },

        get etiquetas() {
            return Object.fromEntries(this.catalogo.map((item) => [item.valor, item.oficio]));
        },

        lineasDeFuera(comision) {
            const persona = this.persona(comision.empleado_id);

            return persona ? lineasFuera(comision, persona, this.etiquetas) : [];
        },

        lineasDeVisitante(visitante) {
            return lineasVisitante(visitante);
        },

        get vistaFormFuera() {
            const form = this.formFuera;

            if (!form?.empleado_id || !form.coordinacion_destino.trim()) {
                return [];
            }

            return this.lineasDeFuera({ ...form, empleado_id: Number(form.empleado_id) });
        },

        get vistaFormVisitante() {
            return this.formVisitante?.direccion_origen.trim() ? lineasVisitante(this.formVisitante) : [];
        },

        // ------------------------------------------------------ asistencia

        tocar(persona) {
            this.sucio = true;
            this.resaltar(`p-${persona.id}`);
        },

        fijarEstatus(persona, valor) {
            persona.estatus = valor;

            if (valor === 'presente') {
                persona.notas = '';
            }
            if (SIN_PERIODO.includes(valor)) {
                persona.fecha_inicio = '';
                persona.fecha_fin = '';
            } else if (!persona.fecha_inicio) {
                persona.fecha_inicio = this.fecha;
            }

            this.tocar(persona);
        },

        continuar(persona) {
            const periodo = persona.continuidad;

            persona.estatus = periodo.estatus;
            persona.fecha_inicio = periodo.fecha_inicio;
            persona.fecha_fin = periodo.fecha_fin;
            persona.notas = periodo.notas;
            this.tocar(persona);
        },

        continuarTodos() {
            this.continuidadesPendientes.forEach((persona) => this.continuar(persona));
        },

        marcarPendientesPresentes() {
            this.personas.filter((persona) => !persona.estatus).forEach((persona) => this.fijarEstatus(persona, 'presente'));
        },

        alGuardar(evento) {
            if (this.pendientes > 0) {
                evento.preventDefault();
                this.soloPendientes = true;
                this.paso = 'asistencia';
                this.avisar(`Faltan ${this.pendientes} por capturar. Te dejé solo esos a la vista.`);

                return;
            }

            this.enviandoAsistencia = true;
            this.sucio = false;
        },

        alSalir(evento) {
            if (this.sucio) {
                evento.preventDefault();
                evento.returnValue = '';
            }
        },

        // ----------------------------------------------------- comisionados

        abrirFuera(comision = null, personaId = '') {
            this.formVisitante = null;
            this.paso = 'comisionados';
            this.formFuera = comision
                ? { ...comision, errores: {}, enviando: false }
                : { ...CAMPOS_VACIOS_FUERA, empleado_id: personaId, errores: {}, enviando: false };
        },

        abrirVisitante(visitante = null) {
            this.formFuera = null;
            this.formVisitante = visitante
                ? { ...visitante, errores: {}, enviando: false }
                : { ...CAMPOS_VACIOS_VISITANTE, errores: {}, enviando: false };
        },

        /** Elegir un visitante conocido llena sus demás datos. */
        autocompletarVisitante() {
            const form = this.formVisitante;
            const conocido = this.sugerencias.visitantes.find(
                (visitante) => visitante.nombre.trim().toUpperCase() === form.nombre.trim().toUpperCase(),
            );

            if (conocido) {
                form.no_trabajador ||= conocido.no_trabajador;
                form.direccion_origen ||= conocido.direccion_origen;
            }
        },

        cabeceras() {
            return { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json' };
        },

        cuerpo(datos) {
            const { id, errores, enviando, ...campos } = datos;

            return { ...campos, estacion: this.estacion.id, fecha: this.fecha };
        },

        /** Guarda en el servidor y devuelve la fila, o null si falló. */
        async enviar(metodo, url, datos, form) {
            try {
                const respuesta = await window.axios({ method: metodo, url, data: this.cuerpo(datos), headers: this.cabeceras() });

                return respuesta.data.data;
            } catch (error) {
                if (form && error.response?.status === 422) {
                    form.errores = error.response.data.errors ?? {};
                } else {
                    this.avisar(MENSAJE_ERROR);
                }

                return null;
            }
        },

        async guardarFuera() {
            const form = this.formFuera;
            const edicion = Boolean(form.id);

            form.errores = {};
            form.enviando = true;

            const fila = await this.enviar(
                edicion ? 'put' : 'post',
                edicion ? this.rutas.fuera.replace('__ID__', form.id) : this.rutas.fueraCrear,
                { ...form, empleado_id: Number(form.empleado_id) },
                form,
            );

            form.enviando = false;

            if (fila) {
                this.registrarFuera(fila);
                this.formFuera = null;
                this.avisar('Comisionado guardado.');
            }
        },

        registrarFuera(fila) {
            const indice = this.fuera.findIndex((comision) => comision.id === fila.id);

            if (indice >= 0) {
                this.fuera[indice] = fila;
            } else {
                this.fuera.push(fila);
            }

            // Sin estatus todavía: al estar comisionado, ese es su estatus del día.
            const persona = this.persona(fila.empleado_id);

            if (persona && !persona.estatus) {
                persona.estatus = 'comision';
                this.sucio = true;
            }

            this.alCambiarComisionados(`f-${fila.id}`);
        },

        async guardarVisitante() {
            const form = this.formVisitante;
            const edicion = Boolean(form.id);

            form.errores = {};
            form.enviando = true;

            const fila = await this.enviar(
                edicion ? 'put' : 'post',
                edicion ? this.rutas.visitante.replace('__ID__', form.id) : this.rutas.visitanteCrear,
                form,
                form,
            );

            form.enviando = false;

            if (fila) {
                this.registrarVisitante(fila);
                this.formVisitante = null;
                this.avisar('Visitante guardado.');
            }
        },

        registrarVisitante(fila) {
            const indice = this.visitantes.findIndex((visitante) => visitante.id === fila.id);

            if (indice >= 0) {
                this.visitantes[indice] = fila;
            } else {
                this.visitantes.push(fila);
            }

            this.alCambiarComisionados(`v-${fila.id}`);
        },

        async quitarFuera(comision) {
            await this.quitar(this.rutas.fuera.replace('__ID__', comision.id), () => {
                this.fuera = this.fuera.filter((fila) => fila.id !== comision.id);
            });
        },

        async quitarVisitante(visitante) {
            await this.quitar(this.rutas.visitante.replace('__ID__', visitante.id), () => {
                this.visitantes = this.visitantes.filter((fila) => fila.id !== visitante.id);
            });
        },

        async quitar(url, alTerminar) {
            this.confirmando = null;

            try {
                await window.axios({ method: 'delete', url, headers: this.cabeceras() });
                alTerminar();
                this.alCambiarComisionados(null);
                this.avisar('Registro quitado.');
            } catch {
                this.avisar(MENSAJE_ERROR);
            }
        },

        /** Trae al día actual un comisionado del último día capturado. */
        async traerFuera(anterior) {
            const fila = await this.enviar('post', this.rutas.fueraCrear, anterior, null);

            if (fila) {
                this.registrarFuera(fila);
            }

            return fila;
        },

        async traerVisitante(anterior) {
            const fila = await this.enviar('post', this.rutas.visitanteCrear, anterior, null);

            if (fila) {
                this.registrarVisitante(fila);
            }

            return fila;
        },

        async traerTodos() {
            this.trayendo = true;

            for (const comision of this.fueraAnterioresDisponibles) {
                await this.traerFuera(comision);
            }
            for (const visitante of this.visitantesAnterioresDisponibles) {
                await this.traerVisitante(visitante);
            }

            this.trayendo = false;
        },

        alCambiarComisionados(clave) {
            this.oficioDesactualizado = this.hayDocumento;

            if (clave) {
                this.resaltar(clave);
            }
        },

        // ---------------------------------------------- oficio y vista previa

        alElegirFirma(evento) {
            if (this.firmaUrl) {
                URL.revokeObjectURL(this.firmaUrl);
            }

            const archivo = evento.target.files[0];

            this.firmaUrl = archivo ? URL.createObjectURL(archivo) : null;
        },

        resaltar(clave) {
            this.resaltado = clave;

            this.$nextTick(() => this.mostrarEnPrevia(clave));

            clearTimeout(this.temporizadorResaltado);
            this.temporizadorResaltado = setTimeout(() => {
                this.resaltado = null;
            }, 1400);
        },

        /** Desplaza solo el panel: scrollIntoView movería también la página mientras se captura. */
        mostrarEnPrevia(clave) {
            const panel = this.$refs.panelPrevia;
            const fila = panel?.querySelector(`[data-fila="${clave}"]`);

            if (!fila) {
                return;
            }

            const marco = panel.getBoundingClientRect();
            const renglon = fila.getBoundingClientRect();

            if (renglon.top < marco.top || renglon.bottom > marco.bottom) {
                panel.scrollBy({ top: renglon.top - marco.top - marco.height / 2, behavior: 'smooth' });
            }
        },

        /** Clic en un renglón de la vista previa: lleva a donde se edita. */
        irAFila(fila) {
            if (fila.personaId) {
                this.paso = 'asistencia';
                this.busqueda = '';
                this.soloPendientes = false;
                this.$nextTick(() => document.getElementById(`persona-${fila.personaId}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
            } else if (fila.comisionId) {
                this.abrirFuera(this.fuera.find((comision) => comision.id === fila.comisionId));
            } else if (fila.visitanteId) {
                this.paso = 'comisionados';
                this.abrirVisitante(this.visitantes.find((visitante) => visitante.id === fila.visitanteId));
            }
        },

        avisar(mensaje) {
            this.aviso = mensaje;
            clearTimeout(this.temporizadorAviso);
            this.temporizadorAviso = setTimeout(() => {
                this.aviso = '';
            }, 3500);
        },
    };
}
