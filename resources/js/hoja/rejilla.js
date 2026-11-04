/**
 * Pintado de la cuadrícula: solo se crean los nodos de las celdas visibles
 * (más un margen), así una hoja de miles de filas se mueve fluida. Los
 * encabezados de columna y los números de fila son paneles aparte que
 * siguen el scroll del cuerpo con transform.
 *
 * Seguridad: todo texto entra con textContent y todo estilo con
 * element.style (colores ya validados con colorSeguro): nunca innerHTML.
 */
import { letrasColumna } from './formulas';
import { colorSeguro } from './motor';

export const ALTO_FILA = 26;
export const ANCHO_COLUMNA = 140;
const MARGEN_FILAS = 6;
const MARGEN_COLUMNAS = 2;

export class Rejilla {
    /**
     * @param {{cuerpo: HTMLElement, lienzo: HTMLElement, celdas: HTMLElement, encabezados: HTMLElement, numeros: HTMLElement, seleccion: HTMLElement, activa: HTMLElement}} refs
     * @param {import('./motor').MotorHoja} motor
     * @param {{filasExtra: number, urlFoto: string}} opciones
     */
    constructor(refs, motor, opciones) {
        this.refs = refs;
        this.motor = motor;
        this.opciones = opciones;
        this.cuadro = null;
        this.marcado = { f1: 0, c1: 0, f2: 0, c2: 0 };
        this.recalcularGeometria();
        refs.cuerpo.addEventListener('scroll', () => this.programar(), { passive: true });
    }

    usarMotor(motor) {
        this.motor = motor;
        this.recalcularGeometria();
    }

    // ------------------------------------------------------------ geometría

    recalcularGeometria() {
        this.x = [0];
        for (const columna of this.motor.columnas) {
            this.x.push(this.x[this.x.length - 1] + (columna.ancho || ANCHO_COLUMNA));
        }
        this.totalFilas = this.motor.filas.length + this.opciones.filasExtra;
        this.refs.lienzo.style.width = `${this.x[this.x.length - 1]}px`;
        this.refs.lienzo.style.height = `${this.totalFilas * ALTO_FILA}px`;
        this.programar();
    }

    anchoColumna(col) {
        return this.x[col + 1] - this.x[col];
    }

    /** Columna bajo la coordenada x del lienzo. */
    columnaEn(x) {
        let bajo = 0;
        let alto = this.motor.columnas.length - 1;
        while (bajo < alto) {
            const medio = Math.ceil((bajo + alto) / 2);
            if (this.x[medio] <= x) {
                bajo = medio;
            } else {
                alto = medio - 1;
            }
        }
        return Math.max(0, bajo);
    }

    filaEn(y) {
        return Math.max(0, Math.min(this.totalFilas - 1, Math.floor(y / ALTO_FILA)));
    }

    /** Celda bajo un evento de ratón (coordenadas de pantalla). */
    celdaDesdeEvento(evento) {
        const caja = this.refs.lienzo.getBoundingClientRect();
        return { fila: this.filaEn(evento.clientY - caja.top), col: this.columnaEn(evento.clientX - caja.left) };
    }

    /** Caja (px, en el lienzo) de un rango. */
    caja(rango) {
        return {
            left: this.x[rango.c1],
            top: rango.f1 * ALTO_FILA,
            width: this.x[rango.c2 + 1] - this.x[rango.c1],
            height: (rango.f2 - rango.f1 + 1) * ALTO_FILA,
        };
    }

    /** Desplaza el scroll lo justo para que se vea la celda. */
    asegurarVisible(fila, col) {
        const cuerpo = this.refs.cuerpo;
        const caja = this.caja({ f1: fila, c1: col, f2: fila, c2: col });
        if (caja.top < cuerpo.scrollTop) {
            cuerpo.scrollTop = caja.top;
        } else if (caja.top + caja.height > cuerpo.scrollTop + cuerpo.clientHeight) {
            cuerpo.scrollTop = caja.top + caja.height - cuerpo.clientHeight;
        }
        if (caja.left < cuerpo.scrollLeft) {
            cuerpo.scrollLeft = caja.left;
        } else if (caja.left + caja.width > cuerpo.scrollLeft + cuerpo.clientWidth) {
            cuerpo.scrollLeft = Math.min(caja.left, caja.left + caja.width - cuerpo.clientWidth);
        }
    }

    // ------------------------------------------------------------ pintado

    programar() {
        if (this.cuadro === null) {
            this.cuadro = requestAnimationFrame(() => {
                this.cuadro = null;
                this.pintar();
            });
        }
    }

    pintar() {
        const { cuerpo } = this.refs;
        const totalColumnas = this.motor.columnas.length;
        if (totalColumnas === 0) {
            this.refs.celdas.replaceChildren();
            return;
        }
        const f0 = Math.max(0, this.filaEn(cuerpo.scrollTop) - MARGEN_FILAS);
        const f1 = Math.min(this.totalFilas - 1, this.filaEn(cuerpo.scrollTop + cuerpo.clientHeight) + MARGEN_FILAS);
        const c0 = Math.max(0, this.columnaEn(cuerpo.scrollLeft) - MARGEN_COLUMNAS);
        const c1 = Math.min(totalColumnas - 1, this.columnaEn(cuerpo.scrollLeft + cuerpo.clientWidth) + MARGEN_COLUMNAS);

        const fragmento = document.createDocumentFragment();
        const pintadas = new Set();

        for (let f = f0; f <= f1; f++) {
            for (let c = c0; c <= c1; c++) {
                const combinada = this.motor.combinacionEn(f, c);
                if (combinada) {
                    const clave = this.motor.clave(combinada.f1, combinada.c1);
                    if (!pintadas.has(clave)) {
                        pintadas.add(clave);
                        fragmento.append(this.celda(combinada.f1, combinada.c1, combinada));
                    }
                    continue;
                }
                fragmento.append(this.celda(f, c, null));
            }
        }
        this.refs.celdas.replaceChildren(fragmento);
        this.pintarEncabezados(c0, c1);
        this.pintarNumeros(f0, f1);
        this.refs.encabezados.style.transform = `translateX(${-cuerpo.scrollLeft}px)`;
        this.refs.numeros.style.transform = `translateY(${-cuerpo.scrollTop}px)`;
    }

    celda(fila, col, combinada) {
        const caja = this.caja(combinada ?? { f1: fila, c1: col, f2: fila, c2: col });
        const nodo = document.createElement('div');
        nodo.className = 'hoja-celda';
        Object.assign(nodo.style, {
            left: `${caja.left}px`,
            top: `${caja.top}px`,
            width: `${caja.width}px`,
            height: `${caja.height}px`,
        });

        const mostrado = this.motor.mostrar(fila, col);
        const tabla = this.motor.estiloDeTabla(fila, col);
        const propio = this.motor.estilo(fila, col);
        const estilo = { ...(tabla ?? {}), ...(propio ?? {}) };

        if (mostrado.foto) {
            const enlace = document.createElement('a');
            enlace.href = this.opciones.urlFoto.replace('__FOTO__', encodeURIComponent(mostrado.foto));
            enlace.target = '_blank';
            enlace.rel = 'noopener';
            enlace.textContent = '📷 Ver foto';
            enlace.className = 'hoja-foto';
            nodo.append(enlace);
        } else {
            nodo.textContent = mostrado.texto;
        }

        if (mostrado.esNumero && !estilo.alin) {
            nodo.style.justifyContent = 'flex-end';
        }
        if (mostrado.esError) {
            nodo.classList.add('hoja-celda-error');
        }
        if (combinada) {
            nodo.classList.add('hoja-celda-combinada');
        }
        this.aplicarEstilo(nodo, estilo);
        return nodo;
    }

    aplicarEstilo(nodo, estilo) {
        if (estilo.n) {
            nodo.style.fontWeight = '600';
        }
        if (estilo.k) {
            nodo.style.fontStyle = 'italic';
        }
        const decoraciones = [estilo.s ? 'underline' : '', estilo.t ? 'line-through' : ''].filter(Boolean);
        if (decoraciones.length) {
            nodo.style.textDecoration = decoraciones.join(' ');
        }
        const color = colorSeguro(estilo.color);
        if (color) {
            nodo.style.color = color;
        }
        const fondo = colorSeguro(estilo.fondo);
        if (fondo) {
            nodo.style.backgroundColor = fondo;
        }
        if (estilo.alin) {
            nodo.style.justifyContent = { izq: 'flex-start', centro: 'center', der: 'flex-end' }[estilo.alin] ?? '';
            nodo.style.textAlign = { izq: 'left', centro: 'center', der: 'right' }[estilo.alin] ?? '';
        }
        if (Number.isInteger(estilo.tam)) {
            nodo.style.fontSize = `${estilo.tam}pt`;
        }
        if (estilo.a) {
            nodo.classList.add('hoja-celda-ajustada');
        }
    }

    pintarEncabezados(c0, c1) {
        const fragmento = document.createDocumentFragment();
        for (let c = c0; c <= c1; c++) {
            const columna = this.motor.columnas[c];
            const nodo = document.createElement('div');
            nodo.className = 'hoja-encabezado';
            nodo.dataset.col = String(c);
            if (c >= this.marcado.c1 && c <= this.marcado.c2) {
                nodo.classList.add('hoja-encabezado-marcado');
            }
            Object.assign(nodo.style, { left: `${this.x[c]}px`, width: `${this.anchoColumna(c)}px` });

            const letra = document.createElement('span');
            letra.className = 'hoja-encabezado-letra';
            letra.textContent = letrasColumna(c);
            const nombre = document.createElement('span');
            nombre.className = 'hoja-encabezado-nombre';
            nombre.textContent = columna.nombre;
            nombre.title = columna.nombre;
            const asa = document.createElement('span');
            asa.className = 'hoja-asa';
            asa.dataset.asa = String(c);
            asa.setAttribute('aria-hidden', 'true');
            nodo.append(letra, nombre, asa);
            fragmento.append(nodo);
        }
        this.refs.encabezados.replaceChildren(fragmento);
        this.refs.encabezados.style.width = `${this.x[this.x.length - 1]}px`;
    }

    pintarNumeros(f0, f1) {
        const fragmento = document.createDocumentFragment();
        const reales = this.motor.filas.length;
        for (let f = f0; f <= f1; f++) {
            const nodo = document.createElement('div');
            nodo.className = 'hoja-numero';
            nodo.dataset.fila = String(f);
            nodo.style.top = `${f * ALTO_FILA}px`;
            nodo.textContent = String(f + 1);
            if (f >= reales) {
                nodo.classList.add('hoja-numero-vacia');
            } else if (this.motor.filas[f].estacion) {
                nodo.title = `Estación: ${this.motor.filas[f].estacion}`;
            }
            if (f >= this.marcado.f1 && f <= this.marcado.f2) {
                nodo.classList.add('hoja-numero-marcado');
            }
            fragmento.append(nodo);
        }
        this.refs.numeros.replaceChildren(fragmento);
        this.refs.numeros.style.height = `${this.totalFilas * ALTO_FILA}px`;
    }

    /**
     * @param {{f1: number, c1: number, f2: number, c2: number}} rango
     * @param {{fila: number, col: number}} activa
     */
    pintarSeleccion(rango, activa) {
        this.marcado = rango;
        Object.assign(this.refs.seleccion.style, this.px(this.caja(rango)));
        const combinada = this.motor.combinacionEn(activa.fila, activa.col);
        Object.assign(this.refs.activa.style, this.px(this.caja(combinada ?? { f1: activa.fila, c1: activa.col, f2: activa.fila, c2: activa.col })));
        this.programar();
    }

    px(caja) {
        return { left: `${caja.left}px`, top: `${caja.top}px`, width: `${caja.width}px`, height: `${caja.height}px` };
    }
}
