/**
 * Panel de preferencias de accesibilidad (<x-panel-accesibilidad>). MISMO ARCHIVO en Portal, Dashboard y Tickets.
 *
 * Guarda la elección en la cookie "a11y" del dominio común y en localStorage; public/js/accesibilidad-inicial.js la
 * aplica al cargar. Compatible con @alpinejs/csp: las vistas solo nombran propiedades y métodos.
 */
const TAMANOS = ['100', '112', '125', '150'];
const OPCIONES = { contraste: 'c', movimiento: 'm', subrayado: 'e' };
const CLASES = { contraste: 'a11y-contraste', movimiento: 'a11y-sin-movimiento', subrayado: 'a11y-subrayado' };

/** Dominio común (".zonaorientetm.tech"); en localhost o una IP, el host. Igual que la cookie "tema". */
function dominioCookie(host) {
    const partes = host.split('.');

    return /^[\d.]+$/.test(host) || partes.length < 2 ? '' : `; domain=.${partes.slice(-2).join('.')}`;
}

function guardar(valor) {
    const seguro = window.location.protocol === 'https:' ? '; Secure' : '';
    const vida = valor === null ? 0 : 31536000;
    document.cookie = `a11y=${valor ?? ''}; max-age=${vida}; path=/; SameSite=Lax${dominioCookie(window.location.hostname)}${seguro}`;

    try {
        if (valor === null) {
            window.localStorage.removeItem('a11y');
        } else {
            window.localStorage.setItem('a11y', valor);
        }
    } catch {
        // Sin almacenamiento (ventana privada): queda la cookie.
    }
}

/** Lo que está aplicado ahora mismo en <html>. */
export function leerPreferencias(raiz = document.documentElement) {
    return {
        texto: TAMANOS.find((t) => raiz.classList.contains(`a11y-texto-${t}`)) ?? '100',
        contraste: raiz.classList.contains(CLASES.contraste),
        movimiento: raiz.classList.contains(CLASES.movimiento),
        subrayado: raiz.classList.contains(CLASES.subrayado),
    };
}

export function codificar(preferencias) {
    const partes = preferencias.texto === '100' ? [] : [`t${preferencias.texto}`];

    for (const [nombre, letra] of Object.entries(OPCIONES)) {
        if (preferencias[nombre]) {
            partes.push(letra);
        }
    }

    return partes.join('.') || 'n';
}

function aplicar(preferencias) {
    const clases = document.documentElement.classList;

    for (const t of TAMANOS) {
        clases.toggle(`a11y-texto-${t}`, t !== '100' && t === preferencias.texto);
    }
    for (const [nombre, clase] of Object.entries(CLASES)) {
        clases.toggle(clase, preferencias[nombre]);
    }

    window.dispatchEvent(new CustomEvent('a11y-cambiado', { detail: preferencias }));
}

export function registrarAccesibilidad(Alpine) {
    Alpine.data('panelAccesibilidad', () => ({
        aviso: '',

        abrir() {
            this.aviso = '';
            this.sincronizar();
            this.$refs.dialogo.showModal();
        },

        /** Escape cierra solo el panel, no el menú que lo contiene. */
        retener() {},

        cerrar() {
            this.$refs.dialogo.close();
        },

        /** Marca los controles según lo aplicado (hay un panel en el menú de escritorio y otro en el de celular). */
        sincronizar() {
            const actual = leerPreferencias();

            for (const control of this.$refs.dialogo.querySelectorAll('[data-a11y]')) {
                const nombre = control.dataset.a11y;
                control.checked = nombre === 'texto' ? control.value === actual.texto : actual[nombre];
            }
        },

        cambiar(evento) {
            const control = evento.target;
            const nombre = control.dataset.a11y;

            if (!nombre) {
                return;
            }

            const preferencias = leerPreferencias();
            preferencias[nombre] = nombre === 'texto' ? control.value : control.checked;
            aplicar(preferencias);
            guardar(codificar(preferencias));
            this.aviso = this.$refs.dialogo.dataset.guardado;
        },

        restablecer() {
            guardar(null);
            aplicar({
                texto: '100',
                contraste: window.matchMedia('(prefers-contrast: more)').matches,
                movimiento: false,
                subrayado: false,
            });
            this.sincronizar();
            this.aviso = this.$refs.dialogo.dataset.restablecido;
        },
    }));
}
