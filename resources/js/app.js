import './bootstrap';
// Tipografía servida desde el mismo sitio (igual en Tickets; sin CDN externos).
import '@fontsource/figtree/400.css';
import '@fontsource/figtree/500.css';
import '@fontsource/figtree/600.css';
import '@fontsource/poppins/600.css';
import '@fontsource/poppins/700.css';
import '@fontsource/poppins/800.css';

import Alpine from 'alpinejs';
import { Chart } from 'chart.js/auto';
import { registrarAsistente } from './asistente';
import { registrarSecciones } from './secciones';
import { hojaCalculo } from './hoja/componente';
import { capturaAsistencia } from './asistencia/captura';
import { ajustarGraficas, selectorTema } from './tema';
import { registrarAccesibilidad } from './accesibilidad';
import { iniciarAccesibilidad } from './accesibilidad-base';
import '../css/accesibilidad.css';
import { registrarTemayin } from './temayin';
import { registrarNotificaciones } from './notificaciones';
import { registrarCambiosSinGuardar } from './cambios-sin-guardar';
import { registrarFlotante } from './flotante';
import { siguienteEnLista } from './teclado-lista';
import { durante, mostrar, ocultar, registrar as registrarPantalla } from './pantalla-carga';

window.Alpine = Alpine;
window.Chart = Chart;

/**
 * Quita acentos/diacríticos y normaliza a minúsculas, para comparar texto
 * sin importar acentos (p. ej. "vacaciones" debe encontrar "Rol de
 * vacaciones"). Función de módulo (no un método duplicado por componente)
 * para que cada buscador de la app comparta la misma lógica de plegado de
 * acentos en vez de repetirla.
 *
 * @param {string} texto
 * @returns {string}
 */
function normalizarTexto(texto) {
    let resultado = '';
    for (const caracter of (texto ?? '').toString().normalize('NFD')) {
        const codigo = caracter.codePointAt(0);
        if (codigo < 0x300 || codigo > 0x36f) {
            resultado += caracter;
        }
    }
    return resultado.toLowerCase();
}

/**
 * Buscador de la barra de navegación: filtra en el navegador la lista de
 * destinos del menú (ya autorizada y armada en el servidor según el rol
 * del usuario — ver resources/views/layouts/navigation.blade.php) por
 * texto sin acentos. No consulta ningún endpoint nuevo.
 *
 * @param {{label: string, href: string}[]} items
 */
function navBuscador(items) {
    return {
        query: '',
        abierto: false,
        items,
        get resultados() {
            const consulta = normalizarTexto(this.query).trim();
            if (!consulta) {
                return [];
            }
            return this.items
                .filter((item) => normalizarTexto(item.label).includes(consulta))
                .slice(0, 8);
        },
        /** Flechas entre el campo y los resultados; Escape cierra y vuelve al campo. */
        teclas(evento, idCampo) {
            const campo = document.getElementById(idCampo);
            if (evento.key === 'Escape') {
                if (this.abierto && this.query !== '') {
                    // Escape cierra solo la búsqueda, no el menú que la contiene.
                    evento.preventDefault();
                    evento.stopPropagation();
                }
                if (evento.target === campo) {
                    this.query = '';
                } else {
                    campo.focus();
                }
                this.abierto = false;
                return;
            }
            const enlaces = [...evento.currentTarget.querySelectorAll('ul a')];
            const destino = siguienteEnLista(evento.key, enlaces.indexOf(evento.target), enlaces.length);
            if (destino === null) {
                return;
            }
            evento.preventDefault();
            const enfocar = () => (destino === -1 ? campo : enlaces[destino])?.focus();
            if (this.abierto) {
                enfocar();
            } else {
                // Con la lista cerrada, primero hay que pintarla.
                this.abierto = true;
                this.$nextTick(enfocar);
            }
        },
        irAlPrimero() {
            const primero = this.resultados[0];
            if (primero) {
                mostrar();
                window.location.href = primero.href;
            }
        },
    };
}

Alpine.data('navBuscador', navBuscador);

/**
 * Vite emite este archivo como <script type="module">, que SIEMPRE se
 * ejecuta después de que el documento terminó de parsearse — incluido
 * cualquier <script> inline colocado más abajo en el body (como los
 * bloques de inicialización de gráficas de cada vista). Por eso una vista
 * no puede llamar directamente a una función definida aquí: todavía no
 * existe en ese momento. En vez de eso, cada vista empuja su config a
 * window.__pendingCharts (un simple push a un array no requiere que
 * ninguna función ya exista), y este módulo vacía la cola de forma
 * síncrona apenas se ejecuta — para entonces el parseo ya terminó, así
 * que el <canvas> de cada vista ya está en el DOM sin necesidad de
 * esperar 'DOMContentLoaded'.
 */
// Instancias creadas, indexadas por canvasId — permite que una vista
// (p. ej. un buscador con Alpine) actualice una gráfica ya renderizada
// sin tener que reconstruirla desde cero.
window.__charts = window.__charts || {};
ajustarGraficas(Chart);

(window.__pendingCharts || []).forEach(function (chart) {
    const ctx = document.getElementById(chart.canvasId);

    if (ctx) {
        window.__charts[chart.canvasId] = new Chart(ctx, chart.config);
    }
});
window.__pendingCharts = [];

registrarSecciones(Alpine);
Alpine.data('hojaCalculo', hojaCalculo);
Alpine.data('capturaAsistencia', capturaAsistencia);
registrarAsistente(Alpine);
Alpine.data('selectorTema', selectorTema);
registrarAccesibilidad(Alpine);
registrarTemayin(Alpine);
registrarNotificaciones(Alpine);

registrarCambiosSinGuardar(document, window);
registrarFlotante(document.querySelector('[data-flotante]'));

// Pantalla de carga: window.PantallaCarga la usan también los scripts escritos en las vistas.
window.PantallaCarga = { mostrar, ocultar, durante };
registrarPantalla(document, window);

Alpine.start();
iniciarAccesibilidad(document, window);
