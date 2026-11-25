import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { TIEMPOS, cambiaDePantalla, durante, envioCambiaDePantalla, mostrar, ocultar, registrar } from '../../resources/js/pantalla-carga.js';

// La expresión de descargas se lee del parcial Blade: se prueba la que de verdad llega al navegador.
const parcial = readFileSync(new URL('../../resources/views/components/pantalla-carga.blade.php', import.meta.url), 'utf8');
const descargas = parcial.match(/data-descargas="([^"]*)"/)[1];

/** Navegador mínimo: solo lo que usa el módulo. */
function navegador() {
    const temporizadores = new Map();
    let siguiente = 0;
    let reloj = 0;

    const atributos = () => {
        const mapa = new Map();

        return {
            hasAttribute: (nombre) => mapa.has(nombre),
            setAttribute: (nombre, valor) => mapa.set(nombre, valor),
            removeAttribute: (nombre) => mapa.delete(nombre),
        };
    };

    const salida = { ...atributos() };
    salida.setAttribute('hidden', '');
    const aviso = { dataset: { texto: 'Cargando…' }, textContent: '' };
    const pantalla = {
        dataset: { descargas },
        querySelector: (selector) => (selector === '[data-cerrar-carga]' ? salida : aviso),
    };
    const contenido = atributos();

    const escuchas = {};
    const oir = (tipo, funcion) => (escuchas[tipo] ??= []).push(funcion);
    const emitir = (tipo, evento = {}) => (escuchas[tipo] ?? []).forEach((funcion) => funcion(evento));

    const doc = {
        activeElement: null,
        body: { children: [contenido, pantalla] },
        getElementById: () => pantalla,
        addEventListener: oir,
        dispatchEvent: (evento) => emitir(evento.type, evento),
    };
    const enviados = [];
    class Formulario {
        submit() {
            enviados.push(this);
        }
    }
    const win = {
        location: new URL('https://dashboard.ejemplo.mx/insumos?mes=11'),
        HTMLFormElement: Formulario,
        CustomEvent: class {
            constructor(type) {
                this.type = type;
            }
        },
        addEventListener: oir,
        setTimeout: (funcion, espera) => {
            temporizadores.set(++siguiente, { funcion, cuando: reloj + espera });

            return siguiente;
        },
        clearTimeout: (id) => temporizadores.delete(id),
    };

    const avanzar = (milisegundos) => {
        reloj += milisegundos;
        let pendiente;

        do {
            pendiente = [...temporizadores].find(([, t]) => t.cuando <= reloj);

            if (pendiente) {
                temporizadores.delete(pendiente[0]);
                pendiente[1].funcion();
            }
        } while (pendiente);
    };

    // El módulo conserva estado entre pruebas: se limpia tras registrar.
    registrar(doc, win);
    ocultar();

    return { doc, win, pantalla, contenido, salida, aviso, enviados, Formulario, emitir, avanzar };
}

function enlace(href, atributos = {}) {
    const todos = { href, ...atributos };

    return {
        getAttribute: (nombre) => todos[nombre] ?? null,
        hasAttribute: (nombre) => nombre in todos,
        closest: (selector) => (selector === '[data-sin-carga]' && 'data-sin-carga' in todos ? {} : null),
    };
}

function formulario(atributos = {}) {
    return {
        getAttribute: (nombre) => atributos[nombre] ?? null,
        closest: () => ('data-sin-carga' in atributos ? {} : null),
    };
}

test('los enlaces a otra pantalla activan la carga, incluso a otro sistema', () => {
    navegador();

    for (const href of ['/dashboard', '?mes=12', 'https://tickets.ejemplo.mx/tickets', '/horarios/consolidados', '/ptar/oficio', '/secciones/5']) {
        assert.equal(cambiaDePantalla(enlace(href)), true, href);
    }
});

test('anclas, otra pestaña, correo, teléfono y excepciones no la activan', () => {
    navegador();

    const casos = [
        enlace('#'), enlace('#abajo'), enlace('/insumos?mes=11#abajo'), enlace(''),
        enlace('/dashboard', { target: '_blank' }), enlace('/dashboard', { download: '' }),
        enlace('/dashboard', { 'data-sin-carga': '' }),
        enlace('mailto:persona@ejemplo.mx'), enlace('tel:9980000000'), enlace('javascript:void(0)'),
    ];

    for (const caso of casos) {
        assert.equal(cambiaDePantalla(caso), false, caso.getAttribute('href'));
    }
});

test('las descargas del Dashboard no la activan', () => {
    navegador();

    const rutas = [
        '/afluencia/descargar?anio=2026', '/agua/descargar', '/agua/3/compras/4/comprobante',
        '/asistencia/documentos/9/docx', '/asistencia/documentos/9/pdf', '/asistencia/oficios-comision/7',
        '/horarios/consolidados/2/descargar', '/insumos/consolidado?anio=2026', '/insumos/cotizaciones/descargar',
        '/insumos/cotizaciones/4/archivo', '/insumos/facturas/descargar?tipo=compra', '/insumos/facturas/8/archivo/xml',
        '/insumos/8/formato', '/insumos/8/partidas/2/ficha', '/papeleria/descargar', '/ptar/descargar',
        '/ptar/3/evidencias/5', '/secciones/rr-hh/exportar?q=a', '/secciones/rr-hh/archivos/12',
        '/secciones/4/fotos/12', '/vacaciones/periodos/1/consolidado',
    ];

    for (const ruta of rutas) {
        assert.equal(cambiaDePantalla(enlace(ruta)), false, ruta);
    }
});

test('los formularios cambian de pantalla salvo descargas, otra pestaña, diálogos y excepciones', () => {
    navegador();

    assert.equal(envioCambiaDePantalla(formulario({ method: 'POST', action: '/insumos' })), true);
    assert.equal(envioCambiaDePantalla(formulario({ action: '/insumos' })), true);
    // Borrar o subir en una ruta que en GET descarga sí recarga la página.
    assert.equal(envioCambiaDePantalla(formulario({ method: 'POST', action: '/secciones/4/archivos/12' })), true);
    assert.equal(envioCambiaDePantalla(formulario({ method: 'get', action: '/ptar/descargar' })), false);
    assert.equal(envioCambiaDePantalla(formulario({ method: 'POST', 'data-sin-carga': '' })), false);
    assert.equal(envioCambiaDePantalla(formulario({ target: '_blank' })), false);
    assert.equal(envioCambiaDePantalla(formulario({ method: 'dialog' })), false);
});

test('bloquea al instante, se ve poco después, ofrece cerrar y nunca se queda pegada', () => {
    const n = navegador();

    mostrar();
    assert.equal(n.pantalla.dataset.estado, 'bloqueando');
    assert.equal(n.contenido.hasAttribute('inert'), true);

    n.avanzar(TIEMPOS.visible);
    assert.equal(n.pantalla.dataset.estado, 'visible');
    assert.equal(n.aviso.textContent, 'Cargando…');

    n.avanzar(TIEMPOS.salida);
    assert.equal(n.salida.hasAttribute('hidden'), false);

    n.avanzar(TIEMPOS.limite);
    assert.equal(n.pantalla.dataset.estado, undefined);
    assert.equal(n.contenido.hasAttribute('inert'), false);
});

test('un envío cancelado por otro manejador no la muestra', () => {
    const n = navegador();

    n.emitir('submit', { defaultPrevented: true, target: formulario({ method: 'POST' }) });
    n.avanzar(0);
    assert.equal(n.pantalla.dataset.estado, undefined);

    n.emitir('submit', { defaultPrevented: false, target: formulario({ method: 'POST' }) });
    n.avanzar(0);
    assert.equal(n.pantalla.dataset.estado, 'bloqueando');
});

test('form.submit() la muestra y quedarse tras el aviso de cambios sin guardar la quita', () => {
    const n = navegador();
    const form = Object.assign(new n.Formulario(), formulario({ method: 'POST' }));

    form.submit();
    assert.deepEqual(n.enviados, [form]);
    assert.equal(n.pantalla.dataset.estado, 'bloqueando');

    n.emitir('beforeunload', { defaultPrevented: true });
    n.avanzar(0);
    assert.equal(n.pantalla.dataset.estado, undefined);
});

test('volver con "Atrás" la quita', () => {
    const n = navegador();

    mostrar();
    n.emitir('pageshow');
    assert.equal(n.pantalla.dataset.estado, undefined);
});

test('durante() la mantiene hasta que terminan todas las esperas, aunque alguna falle', async () => {
    const n = navegador();
    let terminar;
    const lenta = durante(new Promise((resolve) => (terminar = resolve)));

    await assert.rejects(durante(Promise.reject(new Error('sin red'))));
    assert.equal(n.pantalla.dataset.estado, 'bloqueando');

    terminar('listo');
    assert.equal(await lenta, 'listo');
    assert.equal(n.pantalla.dataset.estado, undefined);
});
