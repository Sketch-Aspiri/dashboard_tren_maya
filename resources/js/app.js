import './bootstrap';

import Alpine from 'alpinejs';
import { Chart } from 'chart.js/auto';

window.Alpine = Alpine;
window.Chart = Chart;

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
(window.__pendingCharts || []).forEach(function (chart) {
    const ctx = document.getElementById(chart.canvasId);

    if (ctx) {
        new Chart(ctx, chart.config);
    }
});
window.__pendingCharts = [];

Alpine.start();
