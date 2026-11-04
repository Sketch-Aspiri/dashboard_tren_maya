/**
 * Motor de fórmulas de la hoja de cálculo, sin eval ni new Function: se
 * tokeniza, se arma un árbol (parser de precedencias) y se evalúa sobre los
 * valores de la hoja. Misma gramática que App\Services\Secciones\Hoja\FormulasHoja
 * (que ajusta referencias al insertar/borrar y traduce a Excel al exportar).
 *
 * "A1" = primera fila de datos, primera columna. Funciones en español o en
 * inglés; "," o ";" separan argumentos.
 */

export class ErrorHoja {
    /** @param {string} codigo */
    constructor(codigo) {
        this.codigo = codigo;
    }

    toString() {
        return this.codigo;
    }
}

export const ERR = {
    div0: new ErrorHoja('#¡DIV/0!'),
    valor: new ErrorHoja('#¡VALOR!'),
    ref: new ErrorHoja('#¡REF!'),
    nombre: new ErrorHoja('#¿NOMBRE?'),
    na: new ErrorHoja('#N/A'),
    ciclo: new ErrorHoja('#¡CICLO!'),
    num: new ErrorHoja('#¡NUM!'),
};

const ERRORES_LITERALES = {
    '#¡REF!': ERR.ref, '#REF!': ERR.ref,
    '#¡DIV/0!': ERR.div0, '#DIV/0!': ERR.div0,
    '#¡VALOR!': ERR.valor, '#VALUE!': ERR.valor,
    '#N/A': ERR.na,
};

const PATRON = new RegExp(
    '^(?:'
        + '(\\s+)'
        + '|("(?:[^"]|"")*")'
        + '|(#(?:¡REF!|REF!|¡DIV\\/0!|DIV\\/0!|¡VALOR!|VALUE!|N\\/A))'
        + '|(\\$?[A-Za-z]{1,3}\\$?\\d{1,7}:\\$?[A-Za-z]{1,3}\\$?\\d{1,7})(?![A-Za-z0-9_.(])'
        + '|(\\$?[A-Za-z]{1,3}\\$?\\d{1,7})(?![A-Za-z0-9_.(])'
        + '|(\\d+(?:\\.\\d+)?(?:[eE][+-]?\\d+)?|\\.\\d+)'
        + '|([A-Za-zÁÉÍÓÚÑáéíóúñ_][A-Za-z0-9ÁÉÍÓÚÑáéíóúñ_.]*)'
        + '|(<>|<=|>=|[-+*/^&=<>%(),;:])'
        + ')',
);
const TIPOS = ['espacio', 'texto', 'error', 'rango', 'celda', 'numero', 'nombre', 'operador'];

export function esFormula(valor) {
    return typeof valor === 'string' && valor.length > 1 && valor.startsWith('=');
}

/** @returns {{tipo: string, texto: string}[]|null} */
export function tokenizar(formula) {
    if (!esFormula(formula) || formula.length > 2000) {
        return null;
    }
    let resto = formula.slice(1);
    const tokens = [];
    while (resto.length > 0) {
        const m = PATRON.exec(resto);
        if (!m || m[0] === '') {
            return null;
        }
        const indice = m.slice(1).findIndex((grupo) => grupo !== undefined);
        tokens.push({ tipo: TIPOS[indice], texto: m[0] });
        resto = resto.slice(m[0].length);
    }
    return tokens;
}

// ---------------------------------------------------------------- referencias

export function letrasColumna(indice) {
    let letras = '';
    let n = indice + 1;
    while (n > 0) {
        const resto = (n - 1) % 26;
        letras = String.fromCharCode(65 + resto) + letras;
        n = Math.floor((n - 1) / 26);
    }
    return letras;
}

export function indiceColumna(letras) {
    let indice = 0;
    for (const letra of letras.toUpperCase()) {
        indice = indice * 26 + (letra.charCodeAt(0) - 64);
    }
    return indice - 1;
}

export function leerCelda(texto) {
    const m = /^(\$?)([A-Za-z]{1,3})(\$?)(\d+)$/.exec(texto);
    if (!m) {
        return null;
    }
    return { colFija: m[1] === '$', col: indiceColumna(m[2]), filaFija: m[3] === '$', fila: Number(m[4]) - 1 };
}

export function escribirCelda(celda) {
    return (celda.colFija ? '$' : '') + letrasColumna(celda.col) + (celda.filaFija ? '$' : '') + (celda.fila + 1);
}

/** "B3" para la posición (fila 2, columna 1). */
export function nombreCelda(fila, col) {
    return letrasColumna(col) + (fila + 1);
}

/**
 * Fórmula copiada de una celda a otra $dFilas/$dCols más allá: las
 * referencias relativas se mueven, las fijas ($) no. Una que sale de la
 * hoja pasa a #¡REF!.
 */
export function trasladar(formula, dFilas, dCols) {
    const tokens = tokenizar(formula);
    if (!tokens || (dFilas === 0 && dCols === 0)) {
        return formula;
    }
    const mover = (texto) => {
        const celda = leerCelda(texto);
        if (!celda.filaFija) {
            celda.fila += dFilas;
        }
        if (!celda.colFija) {
            celda.col += dCols;
        }
        return celda.fila < 0 || celda.col < 0 ? null : escribirCelda(celda);
    };
    let resultado = '';
    for (const token of tokens) {
        if (token.tipo === 'celda') {
            resultado += mover(token.texto) ?? '#¡REF!';
        } else if (token.tipo === 'rango') {
            const [a, b] = token.texto.split(':').map(mover);
            resultado += a && b ? `${a}:${b}` : '#¡REF!';
        } else {
            resultado += token.texto;
        }
    }
    return '=' + resultado;
}

// ---------------------------------------------------------------- parser

const PRECEDENCIA = { '=': 1, '<>': 1, '<': 1, '>': 1, '<=': 1, '>=': 1, '&': 2, '+': 3, '-': 3, '*': 4, '/': 4, '^': 5 };

/** @returns {object} árbol, o {tipo: 'error', valor} si no se puede leer */
export function analizar(formula) {
    const tokens = (tokenizar(formula) ?? [{ tipo: 'invalido', texto: '' }]).filter((t) => t.tipo !== 'espacio');
    let pos = 0;
    const ver = () => tokens[pos];
    const tomar = () => tokens[pos++];
    const esOp = (texto) => ver()?.tipo === 'operador' && ver().texto === texto;

    function primario() {
        const t = tomar();
        if (!t) {
            throw ERR.valor;
        }
        switch (t.tipo) {
            case 'numero':
                return { tipo: 'valor', valor: Number(t.texto) };
            case 'texto':
                return { tipo: 'valor', valor: t.texto.slice(1, -1).replaceAll('""', '"') };
            case 'error':
                return { tipo: 'valor', valor: ERRORES_LITERALES[t.texto] ?? ERR.valor };
            case 'celda':
                return { tipo: 'celda', ...leerCelda(t.texto) };
            case 'rango': {
                const [a, b] = t.texto.split(':').map(leerCelda);
                return { tipo: 'rango', f1: Math.min(a.fila, b.fila), c1: Math.min(a.col, b.col), f2: Math.max(a.fila, b.fila), c2: Math.max(a.col, b.col) };
            }
            case 'nombre': {
                const nombre = t.texto.toUpperCase();
                if (esOp('(')) {
                    tomar();
                    const args = [];
                    if (!esOp(')')) {
                        do {
                            args.push(expresion(0));
                        } while ((esOp(',') || esOp(';')) && tomar());
                    }
                    if (!esOp(')')) {
                        throw ERR.valor;
                    }
                    tomar();
                    return { tipo: 'funcion', nombre, args };
                }
                if (nombre === 'VERDADERO' || nombre === 'TRUE') {
                    return { tipo: 'valor', valor: true };
                }
                if (nombre === 'FALSO' || nombre === 'FALSE') {
                    return { tipo: 'valor', valor: false };
                }
                return { tipo: 'valor', valor: ERR.nombre };
            }
            case 'operador':
                if (t.texto === '(') {
                    const dentro = expresion(0);
                    if (!esOp(')')) {
                        throw ERR.valor;
                    }
                    tomar();
                    return dentro;
                }
                if (t.texto === '-' || t.texto === '+') {
                    return { tipo: 'unario', op: t.texto, arg: expresion(6) };
                }
                throw ERR.valor;
            default:
                throw ERR.valor;
        }
    }

    function expresion(minimo) {
        let izquierda = primario();
        while (esOp('%')) {
            tomar();
            izquierda = { tipo: 'porcentaje', arg: izquierda };
        }
        for (;;) {
            const t = ver();
            const prec = t?.tipo === 'operador' ? PRECEDENCIA[t.texto] : undefined;
            if (prec === undefined || prec <= minimo) {
                return izquierda;
            }
            tomar();
            // "^" asocia a la derecha; los demás, a la izquierda.
            const derecha = expresion(t.texto === '^' ? prec - 1 : prec);
            izquierda = { tipo: 'binario', op: t.texto, izq: izquierda, der: derecha };
        }
    }

    try {
        const arbol = expresion(0);
        if (pos < tokens.length) {
            return { tipo: 'valor', valor: ERR.valor };
        }
        return arbol;
    } catch (error) {
        return { tipo: 'valor', valor: error instanceof ErrorHoja ? error : ERR.valor };
    }
}

// ---------------------------------------------------------------- evaluación

/**
 * @typedef {{valor: (fila: number, col: number) => any, existe: (fila: number, col: number) => boolean}} ContextoHoja
 */

function aNumero(valor) {
    if (valor instanceof ErrorHoja) {
        throw valor;
    }
    if (valor === null || valor === undefined || valor === '') {
        return 0;
    }
    if (typeof valor === 'boolean') {
        return valor ? 1 : 0;
    }
    if (typeof valor === 'number') {
        return valor;
    }
    const n = Number(String(valor).replaceAll(',', '').trim());
    if (Number.isNaN(n)) {
        throw ERR.valor;
    }
    return n;
}

function aTexto(valor) {
    if (valor instanceof ErrorHoja) {
        throw valor;
    }
    if (valor === null || valor === undefined) {
        return '';
    }
    if (typeof valor === 'boolean') {
        return valor ? 'VERDADERO' : 'FALSO';
    }
    return String(valor);
}

function aBooleano(valor) {
    if (valor instanceof ErrorHoja) {
        throw valor;
    }
    if (typeof valor === 'boolean') {
        return valor;
    }
    if (typeof valor === 'string') {
        const t = valor.trim().toUpperCase();
        if (t === 'VERDADERO' || t === 'TRUE') {
            return true;
        }
        if (t === 'FALSO' || t === 'FALSE' || t === '') {
            return false;
        }
    }
    return aNumero(valor) !== 0;
}

function comparar(a, b) {
    const vacio = (v) => v === null || v === undefined || v === '';
    if (vacio(a) && vacio(b)) {
        return 0;
    }
    if (typeof a === 'number' && typeof b === 'number') {
        return a - b;
    }
    if (typeof a === 'string' && typeof b === 'string') {
        return a.localeCompare(b, 'es', { sensitivity: 'base' });
    }
    // Como Excel: números < textos < booleanos.
    const rango = (v) => (typeof v === 'number' || vacio(v) ? 0 : typeof v === 'string' ? 1 : 2);
    if (rango(a) !== rango(b)) {
        return rango(a) - rango(b);
    }
    return aNumero(a) - aNumero(b);
}

/** Todos los valores de los argumentos, aplanando rangos. */
function aplanar(args, ctx) {
    const valores = [];
    for (const arg of args) {
        if (arg.tipo === 'rango') {
            for (let f = arg.f1; f <= arg.f2; f++) {
                for (let c = arg.c1; c <= arg.c2; c++) {
                    valores.push({ valor: ctx.valor(f, c), deRango: true });
                }
            }
        } else {
            valores.push({ valor: evaluarNodo(arg, ctx), deRango: arg.tipo === 'celda' });
        }
    }
    return valores;
}

function numerosDe(args, ctx) {
    const numeros = [];
    for (const { valor, deRango } of aplanar(args, ctx)) {
        if (valor instanceof ErrorHoja) {
            throw valor;
        }
        if (typeof valor === 'number') {
            numeros.push(valor);
        } else if (!deRango) {
            numeros.push(aNumero(valor));
        } else if (typeof valor === 'string' && valor.trim() !== '' && !Number.isNaN(Number(valor.replaceAll(',', '')))) {
            numeros.push(Number(valor.replaceAll(',', '')));
        }
    }
    return numeros;
}

/** Criterio de CONTAR.SI / SUMAR.SI: ">5", "<>x", "=texto" o un valor. */
function criterio(valor) {
    const texto = typeof valor === 'string' ? valor : null;
    const m = texto === null ? null : /^(<>|<=|>=|=|<|>)(.*)$/.exec(texto);
    const op = m ? m[1] : '=';
    const crudo = m ? m[2] : valor;
    const objetivo = typeof crudo === 'string' && crudo.trim() !== '' && !Number.isNaN(Number(crudo)) ? Number(crudo) : crudo;
    return (celda) => {
        const v = typeof celda === 'string' && typeof objetivo === 'number' && celda.trim() !== '' && !Number.isNaN(Number(celda)) ? Number(celda) : celda;
        const cmp = comparar(v, objetivo);
        switch (op) {
            case '<>': return cmp !== 0;
            case '<': return cmp < 0;
            case '>': return cmp > 0;
            case '<=': return cmp <= 0;
            case '>=': return cmp >= 0;
            default: return cmp === 0;
        }
    };
}

function celdasDeRango(nodo, ctx) {
    if (nodo.tipo === 'rango') {
        const celdas = [];
        for (let f = nodo.f1; f <= nodo.f2; f++) {
            for (let c = nodo.c1; c <= nodo.c2; c++) {
                celdas.push({ f, c, valor: ctx.valor(f, c) });
            }
        }
        return celdas;
    }
    if (nodo.tipo === 'celda') {
        return [{ f: nodo.fila, c: nodo.col, valor: ctx.valor(nodo.fila, nodo.col) }];
    }
    throw ERR.valor;
}

function hoy() {
    const d = new Date();
    return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
}

const FUNCIONES = {
    SUMA: (args, ctx) => numerosDe(args, ctx).reduce((a, b) => a + b, 0),
    PROMEDIO: (args, ctx) => {
        const n = numerosDe(args, ctx);
        if (n.length === 0) {
            throw ERR.div0;
        }
        return n.reduce((a, b) => a + b, 0) / n.length;
    },
    MIN: (args, ctx) => {
        const n = numerosDe(args, ctx);
        return n.length ? Math.min(...n) : 0;
    },
    MAX: (args, ctx) => {
        const n = numerosDe(args, ctx);
        return n.length ? Math.max(...n) : 0;
    },
    CONTAR: (args, ctx) => aplanar(args, ctx).filter(({ valor }) => typeof valor === 'number'
        || (typeof valor === 'string' && valor.trim() !== '' && !Number.isNaN(Number(valor.replaceAll(',', ''))))).length,
    CONTARA: (args, ctx) => aplanar(args, ctx).filter(({ valor }) => valor !== null && valor !== undefined && valor !== '').length,
    'CONTAR.SI': (args, ctx) => {
        if (args.length !== 2) {
            throw ERR.valor;
        }
        const cumple = criterio(evaluarNodo(args[1], ctx));
        return celdasDeRango(args[0], ctx).filter(({ valor }) => cumple(valor)).length;
    },
    'SUMAR.SI': (args, ctx) => {
        if (args.length < 2 || args.length > 3) {
            throw ERR.valor;
        }
        const cumple = criterio(evaluarNodo(args[1], ctx));
        const base = celdasDeRango(args[0], ctx);
        const sumar = args[2] ? celdasDeRango(args[2], ctx) : base;
        return base.reduce((total, celda, i) => {
            if (!cumple(celda.valor)) {
                return total;
            }
            const v = sumar[i]?.valor;
            const n = typeof v === 'number' ? v : Number(String(v ?? '').replaceAll(',', ''));
            return Number.isNaN(n) ? total : total + n;
        }, 0);
    },
    SI: (args, ctx) => {
        if (args.length < 2 || args.length > 3) {
            throw ERR.valor;
        }
        if (aBooleano(evaluarNodo(args[0], ctx))) {
            return evaluarNodo(args[1], ctx);
        }
        return args[2] ? evaluarNodo(args[2], ctx) : false;
    },
    'SI.ERROR': (args, ctx) => {
        if (args.length !== 2) {
            throw ERR.valor;
        }
        let valor;
        try {
            valor = evaluarNodo(args[0], ctx);
        } catch (error) {
            valor = error instanceof ErrorHoja ? error : ERR.valor;
        }
        return valor instanceof ErrorHoja ? evaluarNodo(args[1], ctx) : valor;
    },
    Y: (args, ctx) => aplanar(args, ctx).every(({ valor }) => aBooleano(valor)),
    O: (args, ctx) => aplanar(args, ctx).some(({ valor }) => aBooleano(valor)),
    NO: (args, ctx) => {
        if (args.length !== 1) {
            throw ERR.valor;
        }
        return !aBooleano(evaluarNodo(args[0], ctx));
    },
    REDONDEAR: (args, ctx) => {
        const n = aNumero(evaluarNodo(args[0], ctx));
        const d = args[1] ? Math.trunc(aNumero(evaluarNodo(args[1], ctx))) : 0;
        const f = 10 ** d;
        return Math.sign(n) * Math.round(Math.abs(n) * f + Number.EPSILON) / f;
    },
    ENTERO: (args, ctx) => Math.floor(aNumero(evaluarNodo(args[0], ctx))),
    ABS: (args, ctx) => Math.abs(aNumero(evaluarNodo(args[0], ctx))),
    POTENCIA: (args, ctx) => aNumero(evaluarNodo(args[0], ctx)) ** aNumero(evaluarNodo(args[1], ctx)),
    RAIZ: (args, ctx) => {
        const n = aNumero(evaluarNodo(args[0], ctx));
        if (n < 0) {
            throw ERR.num;
        }
        return Math.sqrt(n);
    },
    CONCATENAR: (args, ctx) => aplanar(args, ctx).map(({ valor }) => aTexto(valor)).join(''),
    MAYUSC: (args, ctx) => aTexto(evaluarNodo(args[0], ctx)).toUpperCase(),
    MINUSC: (args, ctx) => aTexto(evaluarNodo(args[0], ctx)).toLowerCase(),
    LARGO: (args, ctx) => aTexto(evaluarNodo(args[0], ctx)).length,
    HOY: () => hoy(),
    AHORA: () => {
        const d = new Date();
        return `${hoy()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    },
};

const ALIAS = {
    SUM: 'SUMA', AVERAGE: 'PROMEDIO', COUNT: 'CONTAR', COUNTA: 'CONTARA', COUNTIF: 'CONTAR.SI', SUMIF: 'SUMAR.SI',
    IF: 'SI', IFERROR: 'SI.ERROR', AND: 'Y', OR: 'O', NOT: 'NO', ROUND: 'REDONDEAR', INT: 'ENTERO',
    POWER: 'POTENCIA', SQRT: 'RAIZ', CONCATENATE: 'CONCATENAR', CONCAT: 'CONCATENAR', UPPER: 'MAYUSC',
    LOWER: 'MINUSC', LEN: 'LARGO', TODAY: 'HOY', NOW: 'AHORA',
};

/** Nombres que se ofrecen en la ayuda de la barra de fórmulas. */
export const NOMBRES_FUNCIONES = Object.keys(FUNCIONES);

function evaluarNodo(nodo, ctx) {
    switch (nodo.tipo) {
        case 'valor':
            return nodo.valor;
        case 'celda':
            return ctx.existe(nodo.fila, nodo.col) ? ctx.valor(nodo.fila, nodo.col) : ERR.ref;
        case 'rango':
            // Un rango suelto (fuera de una función) vale su primera celda, como en Excel sin matrices.
            return ctx.valor(nodo.f1, nodo.c1);
        case 'unario': {
            const n = aNumero(evaluarNodo(nodo.arg, ctx));
            return nodo.op === '-' ? -n : n;
        }
        case 'porcentaje':
            return aNumero(evaluarNodo(nodo.arg, ctx)) / 100;
        case 'binario':
            return binario(nodo, ctx);
        case 'funcion': {
            const funcion = FUNCIONES[ALIAS[nodo.nombre] ?? nodo.nombre];
            if (!funcion) {
                throw ERR.nombre;
            }
            return funcion(nodo.args, ctx);
        }
        default:
            throw ERR.valor;
    }
}

function binario(nodo, ctx) {
    const a = evaluarNodo(nodo.izq, ctx);
    const b = evaluarNodo(nodo.der, ctx);
    if (a instanceof ErrorHoja) {
        throw a;
    }
    if (b instanceof ErrorHoja) {
        throw b;
    }
    switch (nodo.op) {
        case '+': return aNumero(a) + aNumero(b);
        case '-': return aNumero(a) - aNumero(b);
        case '*': return aNumero(a) * aNumero(b);
        case '/': {
            const divisor = aNumero(b);
            if (divisor === 0) {
                throw ERR.div0;
            }
            return aNumero(a) / divisor;
        }
        case '^': return aNumero(a) ** aNumero(b);
        case '&': return aTexto(a) + aTexto(b);
        case '=': return comparar(a, b) === 0;
        case '<>': return comparar(a, b) !== 0;
        case '<': return comparar(a, b) < 0;
        case '>': return comparar(a, b) > 0;
        case '<=': return comparar(a, b) <= 0;
        case '>=': return comparar(a, b) >= 0;
        default: throw ERR.valor;
    }
}

/**
 * Resultado de un árbol (número, texto, booleano o ErrorHoja).
 *
 * @param {object} arbol
 * @param {ContextoHoja} ctx
 */
export function evaluar(arbol, ctx) {
    try {
        const valor = evaluarNodo(arbol, ctx);
        if (typeof valor === 'number' && !Number.isFinite(valor)) {
            return ERR.num;
        }
        return valor ?? 0;
    } catch (error) {
        return error instanceof ErrorHoja ? error : ERR.valor;
    }
}
