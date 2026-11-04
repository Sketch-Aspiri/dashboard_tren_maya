/**
 * Modelo de una hoja en el navegador: columnas, filas (en orden de hoja),
 * diseño (combinaciones y formato de tabla) y el cálculo de las fórmulas
 * con caché y detección de ciclos. No toca el DOM.
 */
import { analizar, ERR, ErrorHoja, esFormula, evaluar } from './formulas';

/** Colores de "formato como tabla" (mismos que App\Support\EstiloCelda::ESTILOS_TABLA). */
export const ESTILOS_TABLA = {
    verde: { encabezado: '#0f5132', banda: '#e8f3ee', nombre: 'Verde' },
    azul: { encabezado: '#1e3a8a', banda: '#e8eefb', nombre: 'Azul' },
    gris: { encabezado: '#374151', banda: '#f1f2f4', nombre: 'Gris' },
    ambar: { encabezado: '#92400e', banda: '#fdf3e1', nombre: 'Ámbar' },
};

const PATRON_COLOR = /^#[0-9a-f]{6}$/;

const formatoNumero = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 4 });

/**
 * @typedef {{id: number, clave: string, nombre: string, tipo: string, opciones: string[], ancho: number|null}} Columna
 * @typedef {{id: number, datos: Object<string, any>, formato: Object<string, Object>, estacion: string|null}} Fila
 * @typedef {{f1: number, c1: number, f2: number, c2: number}} Rango
 */
export class MotorHoja {
    /**
     * @param {{columnas: Columna[], filas: Fila[], diseno: {combinaciones: Rango[], tablas: Array<Rango & {estilo: string, encabezado: boolean, bandas: boolean}>}}} datos
     */
    constructor(datos) {
        this.columnas = datos.columnas;
        this.filas = datos.filas.map((fila) => ({ ...fila, datos: { ...fila.datos }, formato: { ...fila.formato } }));
        this.diseno = {
            combinaciones: [...(datos.diseno?.combinaciones ?? [])],
            tablas: [...(datos.diseno?.tablas ?? [])],
        };
        this.cache = new Map();
        this.arboles = new Map();
        this.evaluando = new Set();
        this.indexarDiseno();
    }

    // ------------------------------------------------------------ valores

    /** Valor guardado (lo que se edita), o null. */
    crudo(fila, col) {
        const columna = this.columnas[col];
        const registro = this.filas[fila];
        if (!columna || !registro) {
            return null;
        }
        const valor = registro.datos[columna.clave];
        return valor === undefined ? null : valor;
    }

    /** Texto que se pone en el editor / barra de fórmulas. */
    textoEditable(fila, col) {
        const valor = this.crudo(fila, col);
        const tipo = this.columnas[col]?.tipo;
        if (valor === null) {
            return '';
        }
        if (typeof valor === 'boolean') {
            return valor ? 'Sí' : 'No';
        }
        if (tipo === 'fecha' && typeof valor === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(valor)) {
            const [a, m, d] = valor.split('-');
            return `${d}/${m}/${a}`;
        }
        return String(valor);
    }

    /** Valor calculado (fórmulas evaluadas) para usar en otras fórmulas. */
    valor(fila, col) {
        const crudo = this.crudo(fila, col);
        if (!esFormula(crudo)) {
            return this.valorPlano(crudo, this.columnas[col]?.tipo);
        }
        const clave = fila * 1024 + col;
        if (this.cache.has(clave)) {
            return this.cache.get(clave);
        }
        if (this.evaluando.has(clave)) {
            return ERR.ciclo;
        }
        this.evaluando.add(clave);
        let arbol = this.arboles.get(crudo);
        if (!arbol) {
            arbol = analizar(crudo);
            this.arboles.set(crudo, arbol);
        }
        const resultado = evaluar(arbol, {
            existe: (f, c) => f >= 0 && c >= 0 && c < this.columnas.length,
            valor: (f, c) => this.valor(f, c),
        });
        this.evaluando.delete(clave);
        this.cache.set(clave, resultado);
        return resultado;
    }

    valorPlano(crudo, tipo) {
        if (crudo === null || typeof crudo === 'number' || typeof crudo === 'boolean') {
            return crudo;
        }
        // Texto con forma de número (p. ej. importado en una columna de texto) suma como número.
        const limpio = String(crudo).trim();
        if (tipo !== 'telefono' && /^-?\d{1,3}(,\d{3})*(\.\d+)?$|^-?\d+(\.\d+)?$/.test(limpio)) {
            return Number(limpio.replaceAll(',', ''));
        }
        if (tipo === 'fecha' && /^\d{4}-\d{2}-\d{2}$/.test(limpio)) {
            return limpio.split('-').reverse().join('/');
        }
        return limpio;
    }

    /** Lo que se pinta en la celda. */
    mostrar(fila, col) {
        const columna = this.columnas[col];
        const crudo = this.crudo(fila, col);
        if (crudo === null || crudo === '') {
            return { texto: '', esError: false, esNumero: false };
        }
        if (columna?.tipo === 'foto' && !esFormula(crudo)) {
            return { texto: '📷', esError: false, esNumero: false, foto: String(crudo) };
        }
        const valor = esFormula(crudo) ? this.valor(fila, col) : crudo;
        if (valor instanceof ErrorHoja) {
            return { texto: valor.codigo, esError: true, esNumero: false };
        }
        if (typeof valor === 'number') {
            return { texto: formatoNumero.format(valor), esError: false, esNumero: true };
        }
        if (typeof valor === 'boolean') {
            return { texto: esFormula(crudo) ? (valor ? 'VERDADERO' : 'FALSO') : (valor ? 'Sí' : 'No'), esError: false, esNumero: false };
        }
        return { texto: esFormula(crudo) ? String(valor) : this.textoEditable(fila, col), esError: false, esNumero: false };
    }

    estilo(fila, col) {
        const columna = this.columnas[col];
        return (columna && this.filas[fila]?.formato?.[columna.clave]) || null;
    }

    /** Aplica en el modelo local un valor/formato (ya normalizado o provisional). */
    poner(fila, col, cambio) {
        const registro = this.filas[fila];
        const columna = this.columnas[col];
        if (!registro || !columna) {
            return;
        }
        if ('valor' in cambio) {
            const datos = { ...registro.datos };
            if (cambio.valor === null || cambio.valor === '') {
                delete datos[columna.clave];
            } else {
                datos[columna.clave] = cambio.valor;
            }
            registro.datos = datos;
        }
        if ('formato' in cambio) {
            const formato = { ...registro.formato };
            if (cambio.formato) {
                formato[columna.clave] = cambio.formato;
            } else {
                delete formato[columna.clave];
            }
            registro.formato = formato;
        }
        this.invalidar();
    }

    /** Reemplaza datos y formato de una fila con lo que devolvió el servidor. */
    sincronizarFila(id, datos, formato) {
        const registro = this.filas.find((fila) => fila.id === id);
        if (registro) {
            registro.datos = { ...datos };
            registro.formato = { ...formato };
            this.invalidar();
        }
    }

    invalidar() {
        this.cache.clear();
    }

    posicionDeFila(id) {
        return this.filas.findIndex((fila) => fila.id === id);
    }

    // ------------------------------------------------------------ diseño

    indexarDiseno() {
        this.cubiertas = new Map();
        this.maestras = new Map();
        for (const rango of this.diseno.combinaciones) {
            this.maestras.set(this.clave(rango.f1, rango.c1), rango);
            for (let f = rango.f1; f <= rango.f2; f++) {
                for (let c = rango.c1; c <= rango.c2; c++) {
                    this.cubiertas.set(this.clave(f, c), rango);
                }
            }
        }
    }

    clave(fila, col) {
        return fila * 1024 + col;
    }

    /** Combinación que contiene la celda, o null. */
    combinacionEn(fila, col) {
        return this.cubiertas.get(this.clave(fila, col)) ?? null;
    }

    /** Estilo que da "formato como tabla" a la celda (encabezado/banda), o null. */
    estiloDeTabla(fila, col) {
        for (const tabla of this.diseno.tablas) {
            if (fila < tabla.f1 || fila > tabla.f2 || col < tabla.c1 || col > tabla.c2) {
                continue;
            }
            const colores = ESTILOS_TABLA[tabla.estilo] ?? ESTILOS_TABLA.verde;
            if (tabla.encabezado && fila === tabla.f1) {
                return { fondo: colores.encabezado, color: '#ffffff', n: true };
            }
            const primera = tabla.encabezado ? tabla.f1 + 1 : tabla.f1;
            if (tabla.bandas && (fila - primera) % 2 === 1) {
                return { fondo: colores.banda };
            }
            return {};
        }
        return null;
    }

    /** Amplía un rango para que no corte ninguna combinación (como Excel). */
    expandir(rango) {
        let r = { ...rango };
        let cambio = true;
        while (cambio) {
            cambio = false;
            for (const m of this.diseno.combinaciones) {
                const toca = m.f1 <= r.f2 && r.f1 <= m.f2 && m.c1 <= r.c2 && r.c1 <= m.c2;
                const dentro = m.f1 >= r.f1 && m.f2 <= r.f2 && m.c1 >= r.c1 && m.c2 <= r.c2;
                if (toca && !dentro) {
                    r = { f1: Math.min(r.f1, m.f1), c1: Math.min(r.c1, m.c1), f2: Math.max(r.f2, m.f2), c2: Math.max(r.c2, m.c2) };
                    cambio = true;
                }
            }
        }
        return r;
    }
}

/** Solo colores #rrggbb (lo demás se ignora al pintar). */
export function colorSeguro(color) {
    return typeof color === 'string' && PATRON_COLOR.test(color) ? color : null;
}
