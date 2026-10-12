import './bootstrap';

import Alpine from 'alpinejs';
import { Chart } from 'chart.js/auto';
import { registrarAsistente } from './asistente';
import { registrarSecciones } from './secciones';

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
        irAlPrimero() {
            const primero = this.resultados[0];
            if (primero) {
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

(window.__pendingCharts || []).forEach(function (chart) {
    const ctx = document.getElementById(chart.canvasId);

    if (ctx) {
        window.__charts[chart.canvasId] = new Chart(ctx, chart.config);
    }
});
window.__pendingCharts = [];

registrarSecciones(Alpine);
registrarAsistente(Alpine);

Alpine.start();
