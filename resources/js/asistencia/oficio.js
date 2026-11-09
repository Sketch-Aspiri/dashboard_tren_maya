/**
 * Arma, a partir del estado de la pantalla de captura, lo que imprime el
 * oficio de estación. Es la versión en el navegador de las reglas de
 * AsistenciaDocumentoDataBuilder + FilaPersonal + FormatoFechaOficio (PHP):
 * si cambia una regla allá, debe cambiar aquí, o la vista previa mentirá.
 * Sin dependencias de Alpine ni del DOM.
 */

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

/** Estatus sin columna propia para un comisionado: cuenta en la columna COMISIÓN. */
const SIN_COLUMNA_PROPIA = ['presente', 'comision'];

/** Orden de las columnas de las tablas a), b) y c). */
const COLUMNAS = [
    'descanso', 'dia_no_laborable', 'licencia_medica', 'comision', 'aviso_incidencia',
    'reporte_ausentismo', 'vacaciones', 'baja', 'nuevo_ingreso', 'presente',
];

const mayus = (texto) => (texto ?? '').toString().toLocaleUpperCase('es-MX');
const dos = (numero) => String(numero).padStart(2, '0');

/** @param {string} iso "YYYY-MM-DD" */
function partes(iso) {
    const [anio, mes, dia] = iso.split('-').map(Number);

    return { anio, mes, dia };
}

const abreviado = (mes) => `${MESES[mes - 1]}.`;

/** "2026-11-08" → "8 Nov. 2026" */
export function fechaCorta(iso) {
    if (!iso) {
        return '';
    }
    const { anio, mes, dia } = partes(iso);
    const nombre = abreviado(mes);

    return `${dia} ${nombre[0].toUpperCase()}${nombre.slice(1)} ${anio}`;
}

const diaMes = ({ dia, mes }) => `${dos(dia)} ${mayus(abreviado(mes))}`;
const diaMesAnio = (fecha) => `${diaMes(fecha)} ${fecha.anio}`;

/** Periodo como lo imprime el Excel: "08 NOV. 2026", "07 AL 19 SEP. 2026", "29 SEP. AL 02 OCT. 2026". */
export function rangoCorto(inicioIso, finIso) {
    const unoInicio = inicioIso || finIso;
    const unoFin = finIso || inicioIso;

    if (!unoInicio) {
        return '';
    }

    const inicio = partes(unoInicio);
    const fin = partes(unoFin);

    if (unoInicio === unoFin) {
        return diaMesAnio(inicio);
    }
    if (inicio.anio === fin.anio && inicio.mes === fin.mes) {
        return `${dos(inicio.dia)} AL ${diaMesAnio(fin)}`;
    }
    if (inicio.anio === fin.anio) {
        return `${diaMes(inicio)} AL ${diaMesAnio(fin)}`;
    }

    return `${diaMesAnio(inicio)} AL ${diaMesAnio(fin)}`;
}

/** Líneas del estatus de una persona, como FilaPersonal::estatusLineas(). */
export function lineasEstatus(persona, etiquetas) {
    let texto = etiquetas[persona.estatus] ?? '';
    const notas = (persona.notas ?? '').trim();

    if (persona.estatus !== 'presente' && notas !== '') {
        texto += ` ${mayus(notas)}`;
    }

    const lineas = [texto];
    const rango = persona.estatus === 'presente' ? '' : rangoCorto(persona.fecha_inicio, persona.fecha_fin);

    return rango ? [...lineas, rango] : lineas;
}

const tieneEstatusPropio = (persona) => Boolean(persona.estatus) && !SIN_COLUMNA_PROPIA.includes(persona.estatus);

/** Las dos líneas de estatus de alguien comisionado a otra área. */
export function lineasFuera(comision, persona, etiquetas) {
    const destino = `${mayus(comision.coordinacion_destino)} ${rangoCorto(comision.fecha_inicio, comision.fecha_fin)}`.trim();

    return tieneEstatusPropio(persona)
        ? [lineasEstatus(persona, etiquetas).join(' '), `COMISIÓN ${destino}`]
        : ['COMISIÓN', destino];
}

/** Las dos líneas de estatus de un visitante de otra coordinación. */
export function lineasVisitante(visitante) {
    return ['PRESENTE', `COMISIÓN ${mayus(visitante.direccion_origen)}`.trim()];
}

function filaPersona(persona, estado, etiquetas) {
    const pendiente = !persona.estatus;

    return {
        clave: `p-${persona.id}`,
        personaId: persona.id,
        dir: estado.direccion,
        trab: persona.no_empleado,
        nombre: mayus(persona.nombre),
        lineas: pendiente ? ['SIN CAPTURAR'] : lineasEstatus(persona, etiquetas),
        ubic: mayus(estado.estacion.nombre),
        negrita: persona.destacada,
        estatusNegrita: persona.destacada || persona.estatus !== 'presente',
        pendiente,
    };
}

function resumenDe(personas) {
    const conteos = Object.fromEntries(COLUMNAS.map((columna) => [columna, 0]));

    personas.forEach((persona) => {
        if (persona.estatus) {
            conteos[persona.estatus] += 1;
        }
    });

    return [...COLUMNAS.map((columna) => conteos[columna]), personas.filter((persona) => persona.estatus).length];
}

/** Tabla d): cada persona cuenta una sola vez (igual que el Excel). */
function resumenComisionados(comisionesFuera, totalVisitantes) {
    const otros = {};
    let permanentes = 0;
    let eventuales = 0;

    comisionesFuera.forEach(({ persona }) => {
        if (tieneEstatusPropio(persona)) {
            otros[persona.estatus] = (otros[persona.estatus] ?? 0) + 1;
        } else if (persona.grupo === 'eventuales') {
            eventuales += 1;
        } else {
            permanentes += 1;
        }
    });

    const de = (estatus) => otros[estatus] ?? 0;

    return [
        de('descanso'), de('dia_no_laborable'), de('licencia_medica'),
        permanentes, eventuales, totalVisitantes,
        de('aviso_incidencia'), de('reporte_ausentismo'), de('vacaciones'),
        de('baja'), de('nuevo_ingreso'), de('presente'),
        comisionesFuera.length + totalVisitantes,
    ];
}

/**
 * @param {object} estado personas, fuera, visitantes, estacion, fecha, formato, direccion, catalogo
 * @returns {object} modelo que dibuja la vista previa
 */
export function armarOficio(estado) {
    const etiquetas = Object.fromEntries(estado.catalogo.map((item) => [item.valor, item.oficio]));
    const posiciones = new Map(estado.personas.map((persona, indice) => [persona.id, indice]));
    const porId = new Map(estado.personas.map((persona) => [persona.id, persona]));

    // Una misma persona con dos destinos se imprime una sola vez, en su primer renglón.
    const vistos = new Set();
    const comisionesFuera = estado.fuera
        .filter((comision) => {
            if (vistos.has(comision.empleado_id) || !porId.has(comision.empleado_id)) {
                return false;
            }
            vistos.add(comision.empleado_id);

            return true;
        })
        .map((comision) => ({ comision, persona: porId.get(comision.empleado_id) }))
        .sort((a, b) => posiciones.get(a.persona.id) - posiciones.get(b.persona.id));

    const enLista = estado.personas.filter((persona) => !vistos.has(persona.id));
    const grupo = (nombre) => enLista.filter((persona) => persona.grupo === nombre);
    const filas = (personas) => personas.map((persona) => filaPersona(persona, estado, etiquetas));

    const filaFuera = ({ comision, persona }) => ({
        clave: `f-${comision.id}`,
        comisionId: comision.id,
        dir: estado.direccion,
        trab: persona.no_empleado,
        nombre: mayus(persona.nombre),
        lineas: lineasFuera(comision, persona, etiquetas),
        ubic: mayus(comision.ubicacion_destino),
        negrita: true,
        estatusNegrita: true,
        pendiente: false,
    });

    const visitantes = [...estado.visitantes]
        .sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'))
        .map((visitante) => ({
            clave: `v-${visitante.id}`,
            visitanteId: visitante.id,
            dir: mayus(visitante.direccion_origen),
            trab: visitante.no_trabajador,
            nombre: mayus(visitante.nombre),
            lineas: lineasVisitante(visitante),
            ubic: mayus(estado.estacion.nombre),
            negrita: true,
            estatusNegrita: true,
            pendiente: false,
        }));

    const esEventual = ({ persona }) => persona.grupo === 'eventuales';
    const fecha = fechaCorta(estado.fecha);
    const ambito = `de la ${estado.estacion.is_operativa ? 'Estación ' : ''}${estado.estacion.nombre}`;

    return {
        fecha,
        parrafo: `En relación al Memorándum No. ${estado.formato.memorandum}, referente a las Directivas para el control de asistencia del personal perteneciente a la Empresa Tren Maya, S.A. de C.V., me permito remitir a esa superioridad, el control de asistencia diaria ${ambito} del ${fecha}.`,
        resumenes: [
            { letra: 'a)', titulo: 'TOTAL, MILITARES EN ACTIVO:', total: 'TOTAL MIL.ACT.', valores: resumenDe(grupo('militares')) },
            { letra: 'b)', titulo: 'TOTAL, PLAZAS PERMANENTES:', total: 'TOTAL PERMANENTES', valores: resumenDe(grupo('permanentes')) },
            { letra: 'c)', titulo: 'TOTAL, PLAZAS EVENTUALES:', total: 'TOTAL EVENTUAL', valores: resumenDe(grupo('eventuales')) },
        ],
        comisionados: resumenComisionados(comisionesFuera, visitantes.length),
        militares: filas(grupo('militares')),
        permanentes: filas(grupo('permanentes')),
        eventuales: filas(grupo('eventuales')),
        seccionE: [
            { titulo: 'a.   PERSONAL COMISIONADO DE OTRAS COORDINACIONES:', filas: visitantes },
            { titulo: 'b.   PERSONAL COMISIONADO A OTRAS ÁREAS:', filas: comisionesFuera.filter((c) => !esEventual(c)).map(filaFuera) },
            { titulo: 'c.   PERSONAL EVENTUAL COMISIONADO A OTRAS ÁREAS:', filas: comisionesFuera.filter(esEventual).map(filaFuera) },
        ],
    };
}
