/**
 * Accesibilidad de base (WCAG 2.2). MISMO ARCHIVO en Portal, Dashboard y Tickets; los datos llegan en
 * <x-accesibilidad-base>. Sin dependencias y compatible con la CSP.
 *
 * 1. Errores del servidor: marca cada campo (aria-invalid y aria-describedby) y arma un resumen con enlaces.
 * 2. Ventanas emergentes propias (role="dialog" + aria-modal): foco al abrir, Tab dentro y regreso al cerrar.
 * 3. Avisos ([data-aviso]): se anuncian al lector de pantalla después de cargar.
 * 4. Zonas con desplazamiento sin nada enfocable: se pueden recorrer con teclado.
 * 5. Sesión: avisa antes de que venza y permite continuar.
 */
const ENFOCABLES = 'iframe, video[controls], audio[controls], [contenteditable="true"], a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';
const VENTANAS = '[role="dialog"][aria-modal="true"], [role="alertdialog"][aria-modal="true"]';
const DESPLAZABLES = '.overflow-x-auto, .overflow-y-auto, .overflow-auto, .ins-tabla-marco';
const AVISO_SESION_MS = 5 * 60 * 1000;

const visible = (el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
const enfocables = (raiz) => [...raiz.querySelectorAll(ENFOCABLES)].filter(visible);
const limpio = (texto) => (texto ?? '').replace(/\s+/g, ' ').trim();

function agregarDescripcion(campo, id) {
    const actuales = (campo.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean);

    if (!actuales.includes(id)) {
        campo.setAttribute('aria-describedby', [...actuales, id].join(' '));
    }
}

/** Nombres con que un error de Laravel ("fechas.0.dia") puede estar en el formulario. */
function nombres(clave) {
    const [base, ...resto] = clave.split('.');
    const corchetes = base + resto.map((parte) => `[${parte}]`).join('');

    return [clave, corchetes, `${corchetes}[]`, ...(resto.length ? [`${base}[]`] : [])];
}

function buscarCampo(documento, clave, mensaje) {
    for (const nombre of nombres(clave)) {
        const campos = [...documento.querySelectorAll(`[name="${CSS.escape(nombre)}"]`)].filter((c) => c.type !== 'hidden');

        if (campos.length) {
            // Con el mismo nombre en varios formularios, el que muestra el mensaje.
            return campos.find((c) => limpio(c.form?.textContent).includes(mensaje)) ?? campos[0];
        }
    }

    return null;
}

function textoDeEtiqueta(campo) {
    return limpio(campo.labels?.[0]?.textContent ?? campo.getAttribute('aria-label'));
}

function marcarErrores(documento, datos) {
    // En base64 para que los mensajes no aparezcan dos veces en el HTML.
    const bytes = Uint8Array.from(atob(datos.dataset.errores || ''), (c) => c.charCodeAt(0));
    const errores = bytes.length ? JSON.parse(new TextDecoder().decode(bytes)) : [];

    if (!errores.length) {
        return;
    }

    const resumen = documento.createElement('div');
    resumen.className = 'a11y-resumen';
    resumen.tabIndex = -1;
    resumen.setAttribute('role', 'group');
    resumen.setAttribute('aria-labelledby', 'a11y-resumen-titulo');
    const titulo = documento.createElement('p');
    titulo.id = 'a11y-resumen-titulo';
    titulo.textContent = datos.dataset.resumenTitulo;
    const lista = documento.createElement('ul');
    resumen.append(titulo, lista);

    // Verdadero mientras todos los errores se vean dentro de una ventana abierta por el propio error.
    let todoEnVentana = true;

    errores.forEach(({ campo: clave, mensaje }, i) => {
        const campo = buscarCampo(documento, clave, mensaje);
        const renglon = documento.createElement('li');
        lista.append(renglon);

        if (!campo) {
            todoEnVentana = false;
            renglon.textContent = mensaje;

            return;
        }

        campo.id ||= `a11y-campo-${i}`;
        const enlace = documento.createElement('a');
        enlace.href = `#${campo.id}`;
        enlace.textContent = mensaje;
        const etiqueta = textoDeEtiqueta(campo);
        if (etiqueta && !mensaje.toLowerCase().includes(etiqueta.toLowerCase())) {
            enlace.textContent = `${etiqueta}: ${mensaje}`;
        }
        enlace.addEventListener('click', (evento) => {
            evento.preventDefault();
            for (let d = campo.closest('details'); d; d = d.parentElement?.closest('details')) {
                d.open = true;
            }
            campo.focus();
        });
        renglon.append(enlace);

        campo.setAttribute('aria-invalid', 'true');
        const visto = [...(campo.form ?? documento).querySelectorAll('li, p, span')]
            .find((n) => n.children.length === 0 && !n.closest('.a11y-resumen') && limpio(n.textContent) === mensaje);
        todoEnVentana = todoEnVentana && Boolean(visto && campo.closest('[aria-modal="true"]'));
        const descripcion = visto ?? renglon;
        descripcion.id ||= `a11y-error-${i}`;
        agregarDescripcion(campo, descripcion.id);
    });

    const destino = documento.querySelector('#contenido') ?? documento.querySelector('main');
    if (destino && !todoEnVentana) {
        destino.prepend(resumen);
        resumen.focus();
    }
}

function vigilarVentanas(documento, ventana) {
    const abiertas = new Map();
    let ultimoFuera = null;
    let pendiente = false;
    const superior = () => [...abiertas.keys()].at(-1);

    const revisar = () => {
        pendiente = false;

        for (const [dialogo, previo] of abiertas) {
            if (!dialogo.isConnected || !visible(dialogo)) {
                abiertas.delete(dialogo);
                const activo = documento.activeElement;
                if ((!activo || activo === documento.body || dialogo.contains(activo)) && previo?.isConnected && visible(previo)) {
                    previo.focus();
                }
            }
        }

        for (const dialogo of documento.querySelectorAll(VENTANAS)) {
            if (abiertas.has(dialogo) || !visible(dialogo)) {
                continue;
            }
            abiertas.set(dialogo, ultimoFuera);
            // Se da tiempo a que el propio componente ponga el foco donde quiera.
            ventana.setTimeout(() => {
                if (superior() === dialogo && !dialogo.contains(documento.activeElement)) {
                    const lista = enfocables(dialogo);
                    (lista.find((el) => el.matches('input, select, textarea')) ?? lista[0])?.focus();
                }
            }, 150);
        }
    };

    const programar = () => {
        if (!pendiente) {
            pendiente = true;
            ventana.requestAnimationFrame(revisar);
        }
    };

    documento.addEventListener('focusin', (evento) => {
        if (!evento.target.closest(VENTANAS)) {
            ultimoFuera = evento.target;
        }
    });

    documento.addEventListener('keydown', (evento) => {
        const dialogo = superior();

        if (evento.key !== 'Tab' || !dialogo || documento.querySelector('dialog[open]')) {
            return;
        }

        const lista = enfocables(dialogo);
        if (!lista.length) {
            return;
        }

        evento.preventDefault();
        evento.stopPropagation();
        const actual = lista.indexOf(documento.activeElement);
        const paso = evento.shiftKey ? -1 : 1;
        const siguiente = actual === -1 ? (evento.shiftKey ? lista.length - 1 : 0) : (actual + paso + lista.length) % lista.length;
        lista[siguiente].focus();
    }, true);

    new ventana.MutationObserver(programar).observe(documento.body, {
        subtree: true, childList: true, attributes: true, attributeFilter: ['style', 'class', 'hidden', 'x-cloak'],
    });
    // Las transiciones terminan después del cambio de atributos.
    documento.addEventListener('transitionend', programar);
    programar();
}

function anunciarAvisos(documento, ventana) {
    const region = documento.querySelector('[data-a11y-anuncio]');
    const avisos = [...documento.querySelectorAll('[data-aviso]')].filter(visible);

    if (region && avisos.length) {
        ventana.setTimeout(() => {
            region.textContent = avisos.map((a) => limpio(a.textContent)).join('. ');
        }, 500);
    }
}

function enfocarDesplazables(documento, datos) {
    for (const zona of documento.querySelectorAll(DESPLAZABLES)) {
        const desborda = zona.scrollWidth > zona.clientWidth + 1 || zona.scrollHeight > zona.clientHeight + 1;

        if (!desborda || zona.hasAttribute('tabindex') || zona.querySelector(ENFOCABLES)) {
            continue;
        }

        zona.tabIndex = 0;
        if (!zona.hasAttribute('role')) {
            zona.setAttribute('role', 'region');
            const nombre = limpio(zona.querySelector('caption')?.textContent) || datos.dataset.zonaNombre;
            zona.setAttribute('aria-label', nombre);
        }
    }
}

function vigilarSesion(documento, ventana, datos) {
    const dialogo = documento.querySelector('dialog[data-a11y-sesion]');
    const duracion = Number(datos.dataset.sesionMinutos) * 60 * 1000;

    if (!dialogo || !duracion) {
        return;
    }

    // Última petición al servidor desde cualquier pestaña: las consultas automáticas también mantienen la sesión.
    const anotar = () => {
        try {
            ventana.localStorage.setItem('a11y-sesion', String(Date.now()));
        } catch {
            // Sin almacenamiento: se usa solo lo de esta pestaña.
        }
        ultima = Date.now();
    };
    let ultima = 0;
    const leer = () => {
        try {
            return Math.max(ultima, Number(ventana.localStorage.getItem('a11y-sesion')) || 0);
        } catch {
            return ultima;
        }
    };
    anotar();

    if (ventana.PerformanceObserver) {
        new ventana.PerformanceObserver((lista) => {
            const propias = lista.getEntries().some((e) => ['fetch', 'xmlhttprequest'].includes(e.initiatorType)
                && e.name.startsWith(ventana.location.origin) && !(e.responseStatus >= 400));
            // Con el plazo ya vencido (equipo suspendido), una consulta no pudo haberla mantenido viva.
            if (propias && leer() + duracion > Date.now()) {
                anotar();
            }
        }).observe({ type: 'resource' });
    }

    const texto = dialogo.querySelector('[data-sesion-texto]');
    const seguir = dialogo.querySelector('[data-sesion-seguir]');
    const entrar = dialogo.querySelector('[data-sesion-entrar]');
    const original = texto.textContent;

    seguir.addEventListener('click', async () => {
        let vigente = false;
        try {
            const respuesta = await ventana.fetch(datos.dataset.sesionUrl, {
                headers: {
                    Accept: 'application/json',
                    // Evita que quede como "página anterior" de la sesión.
                    'X-Requested-With': 'XMLHttpRequest',
                    // Si la sesión se rehízo ("Recordarme"), el token de esta página ya no sirve.
                    'X-CSRF-TOKEN': documento.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            vigente = respuesta.status === 204;
        } catch {
            return;
        }

        if (vigente) {
            anotar();
            texto.textContent = original;
            dialogo.close();
        } else {
            texto.textContent = texto.dataset.vencida;
            seguir.hidden = true;
            entrar.hidden = false;
            entrar.focus();
        }
    });

    ventana.setInterval(() => {
        const restante = leer() + duracion - Date.now();

        if (restante <= AVISO_SESION_MS && !dialogo.open && !seguir.hidden) {
            dialogo.showModal();
        } else if (restante > AVISO_SESION_MS && dialogo.open && !seguir.hidden) {
            dialogo.close();
        }
    }, 15000);
}

export function iniciarAccesibilidad(documento = document, ventana = window) {
    const datos = documento.querySelector('[data-a11y-base]');

    if (!datos) {
        return;
    }

    vigilarVentanas(documento, ventana);
    vigilarSesion(documento, ventana, datos);

    const alCargar = () => {
        marcarErrores(documento, datos);
        anunciarAvisos(documento, ventana);
        enfocarDesplazables(documento, datos);
    };
    // Después de que Alpine haya pintado.
    ventana.setTimeout(alCargar, 50);

    let espera = null;
    ventana.addEventListener('resize', () => {
        ventana.clearTimeout(espera);
        espera = ventana.setTimeout(() => enfocarDesplazables(documento, datos), 300);
    });
}
