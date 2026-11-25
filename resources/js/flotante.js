/**
 * Mantiene el botón flotante de Temayin sin tapar las barras de acciones ni los enlaces del pie: lo sube
 * justo por encima de lo que ocupe su esquina en ese momento.
 */
const MARGEN = 16;
const BOTON = 56;
const AIRE = 12;
const OBSTACULOS = '.ins-barra-acciones, [data-evitar-flotante]';

/**
 * @param {{top: number, bottom: number, left: number, right: number}[]} obstaculos  rectángulos en pantalla
 * @param {{ancho: number, alto: number}} vista
 * @returns {number} píxeles desde el borde inferior
 */
export function calcularSeparacion(obstaculos, vista) {
    const izquierda = vista.ancho - MARGEN - BOTON;
    const enLaEsquina = obstaculos
        .filter((r) => r.right > izquierda && r.left < vista.ancho - MARGEN)
        .sort((a, b) => b.bottom - a.bottom);
    let separacion = MARGEN;

    for (const r of enLaEsquina) {
        const abajo = vista.alto - separacion;
        if (r.top < abajo && r.bottom > abajo - BOTON) {
            separacion = vista.alto - r.top + AIRE;
        }
    }

    // Si no queda lugar arriba (obstáculo tan alto como la pantalla), se queda en su sitio.
    return separacion + BOTON > vista.alto ? MARGEN : separacion;
}

/** @param {HTMLElement|null} flotante */
export function registrarFlotante(flotante) {
    if (!flotante) {
        return;
    }

    let pendiente = false;
    const acomodar = () => {
        pendiente = false;
        const rects = Array.from(document.querySelectorAll(OBSTACULOS))
            .map((elemento) => elemento.getBoundingClientRect())
            .filter((r) => r.height > 0);
        const separacion = calcularSeparacion(rects, { ancho: document.documentElement.clientWidth, alto: window.innerHeight });
        flotante.style.bottom = `${separacion}px`;
    };
    const programar = () => {
        if (!pendiente) {
            pendiente = true;
            window.requestAnimationFrame(acomodar);
        }
    };

    window.addEventListener('scroll', programar, { passive: true });
    window.addEventListener('resize', programar);
    // Cambios de altura sin scroll (agregar partidas, abrir pestañas).
    new ResizeObserver(programar).observe(document.body);
    programar();
}
