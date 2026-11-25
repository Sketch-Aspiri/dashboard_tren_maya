/**
 * Pantalla de carga: cubre y bloquea la página (logo del Tren Maya y puntos animados) mientras se navega, se envía
 * un formulario o un botón espera al servidor. El marcado vive en el parcial Blade `#app-page-loader`.
 *
 * Este archivo es IGUAL en Portal, Dashboard y Tickets: si cambia en uno, copiarlo a los otros dos. Lo propio de cada
 * sistema (las rutas que descargan un archivo) viaja en el atributo `data-descargas` del parcial.
 *
 * - Enlaces: no se activa en otra pestaña, descargas, anclas, correo o teléfono.
 * - Formularios: no se activa si otro manejador canceló el envío (confirmación, validación, envío por AJAX).
 * - Excepciones puntuales: atributo `data-sin-carga` en el enlace, botón, formulario o un contenedor.
 * - Botones que esperan al servidor sin cambiar de página: `durante(promesa)`.
 */

export const TIEMPOS = {
    visible: 150, // antes de esto solo bloquea: las cargas rápidas no parpadean
    salida: 12000, // aparece el botón "Cerrar" por si algo no cambió de página
    limite: 90000, // se quita sola
};

let ventana = null;
let documento = null;
let activa = false;
let esperas = 0;
let temporizadores = [];
let bloqueados = [];
let focoPrevio = null;

function elemento() {
    return documento?.getElementById('app-page-loader') ?? null;
}

export function mostrar() {
    const pantalla = elemento();

    if (!pantalla || activa) {
        return;
    }

    activa = true;
    focoPrevio = documento.activeElement;
    pantalla.dataset.estado = 'bloqueando';

    // `inert` quita el resto de la página del teclado y de los lectores de pantalla.
    bloqueados = Array.from(documento.body.children).filter((hijo) => hijo !== pantalla && !hijo.hasAttribute('inert'));
    bloqueados.forEach((hijo) => hijo.setAttribute('inert', ''));

    const salida = pantalla.querySelector('[data-cerrar-carga]');
    const aviso = pantalla.querySelector('[data-aviso-carga]');

    temporizadores = [
        ventana.setTimeout(() => {
            pantalla.dataset.estado = 'visible';

            if (aviso) {
                aviso.textContent = aviso.dataset.texto ?? '';
            }
        }, TIEMPOS.visible),
        ventana.setTimeout(() => salida?.removeAttribute('hidden'), TIEMPOS.salida),
        ventana.setTimeout(ocultar, TIEMPOS.limite),
    ];
}

export function ocultar() {
    esperas = 0;
    temporizadores.forEach((temporizador) => ventana.clearTimeout(temporizador));
    temporizadores = [];

    const pantalla = elemento();

    if (!pantalla || !activa) {
        return;
    }

    activa = false;
    delete pantalla.dataset.estado;
    pantalla.querySelector('[data-cerrar-carga]')?.setAttribute('hidden', '');

    const aviso = pantalla.querySelector('[data-aviso-carga]');

    if (aviso) {
        aviso.textContent = '';
    }

    bloqueados.forEach((hijo) => hijo.removeAttribute('inert'));
    bloqueados = [];

    if (focoPrevio?.isConnected) {
        focoPrevio.focus?.({ preventScroll: true });
    }

    focoPrevio = null;
    documento.dispatchEvent(new ventana.CustomEvent('pantalla-carga:oculta'));
}

/**
 * Mantiene la pantalla mientras se resuelve una petición. Admite esperas anidadas o simultáneas.
 *
 * @template T
 * @param {Promise<T>} promesa
 * @returns {Promise<T>}
 */
export async function durante(promesa) {
    esperas += 1;
    mostrar();

    try {
        return await promesa;
    } finally {
        // `ocultar` (botón "Cerrar" o volver atrás) deja la cuenta en cero: no bajar de ahí.
        if (esperas > 0) {
            esperas -= 1;

            if (esperas === 0) {
                ocultar();
            }
        }
    }
}

function esDescarga(url) {
    const patron = elemento()?.dataset.descargas;

    return Boolean(patron) && new RegExp(patron).test(url.pathname);
}

/**
 * ¿Seguir este enlace cambia de pantalla en esta misma pestaña?
 *
 * @param {HTMLAnchorElement} enlace
 */
export function cambiaDePantalla(enlace) {
    const destino = enlace.getAttribute('target');

    if (
        enlace.hasAttribute('download')
        || enlace.closest('[data-sin-carga]')
        || (destino && destino !== '_self' && destino !== '_top')
    ) {
        return false;
    }

    const escrito = enlace.getAttribute('href') ?? '';

    if (escrito === '' || escrito.startsWith('#')) {
        return false;
    }

    const actual = ventana.location;
    let url;

    try {
        url = new URL(escrito, actual.href);
    } catch {
        return false;
    }

    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return false;
    }

    if (url.origin !== actual.origin) {
        return true;
    }

    // Solo cambia el ancla dentro de la misma página.
    if (escrito.includes('#') && url.pathname === actual.pathname && url.search === actual.search) {
        return false;
    }

    return !esDescarga(url);
}

/**
 * ¿Enviar este formulario cambia de pantalla en esta misma pestaña?
 *
 * @param {HTMLFormElement} formulario
 * @param {HTMLElement|null} boton El botón que lo envió, si lo hubo.
 */
export function envioCambiaDePantalla(formulario, boton = null) {
    const atributo = (delBoton, delFormulario) => boton?.getAttribute(delBoton) ?? formulario.getAttribute(delFormulario);
    const destino = atributo('formtarget', 'target');
    const metodo = (atributo('formmethod', 'method') ?? 'get').toLowerCase();

    if (
        formulario.closest('[data-sin-carga]')
        || boton?.closest('[data-sin-carga]')
        || (destino && destino !== '_self' && destino !== '_top')
        || metodo === 'dialog'
    ) {
        return false;
    }

    // Las rutas de descarga solo se reconocen por dirección en GET: la misma dirección con POST o DELETE sí cambia
    // de página. Un POST que devuelve un archivo se marca con `data-sin-carga`.
    if (metodo !== 'get') {
        return true;
    }

    try {
        return !esDescarga(new URL(atributo('formaction', 'action') || ventana.location.href, ventana.location.href));
    } catch {
        return true;
    }
}

/**
 * Escucha clics, envíos y la vida de la página. Se llama una sola vez, desde app.js.
 *
 * @param {Document} doc
 * @param {Window} win
 */
export function registrar(doc, win) {
    documento = doc;
    ventana = win;

    // Se revisa al final de la cola, cuando los demás manejadores ya pudieron cancelar el evento.
    const alFinal = (evento, accion) => win.setTimeout(() => {
        if (!evento.defaultPrevented) {
            accion();
        }
    }, 0);

    doc.addEventListener('click', (evento) => {
        if (evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) {
            return;
        }

        if (evento.target.closest?.('[data-cerrar-carga]')) {
            ocultar();

            return;
        }

        const enlace = evento.target.closest?.('a[href]');

        if (enlace) {
            alFinal(evento, () => cambiaDePantalla(enlace) && mostrar());
        }
    }, true);

    doc.addEventListener('submit', (evento) => {
        alFinal(evento, () => envioCambiaDePantalla(evento.target, evento.submitter) && mostrar());
    }, true);

    // `form.submit()` (filtros automáticos, cerrar sesión) no emite el evento `submit`.
    const prototipo = win.HTMLFormElement?.prototype;

    if (prototipo) {
        const enviar = prototipo.submit;

        prototipo.submit = function submit() {
            enviar.call(this);

            if (envioCambiaDePantalla(this)) {
                mostrar();
            }
        };
    }

    // Aviso de "cambios sin guardar": si la persona se queda, la pantalla no debe taparle el trabajo.
    win.addEventListener('beforeunload', (evento) => {
        win.setTimeout(() => {
            if (evento.defaultPrevented) {
                ocultar();
            }
        }, 0);
    });

    // Volver con "Atrás" muestra la página guardada en memoria: se limpia.
    win.addEventListener('pageshow', () => ocultar());
}
