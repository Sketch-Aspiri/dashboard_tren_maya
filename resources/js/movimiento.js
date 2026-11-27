/**
 * Desplazamiento suave solo si nadie pidió menos movimiento: la preferencia del panel de accesibilidad
 * (clase en <html>) o la del sistema.
 *
 * @returns {'auto' | 'smooth'}
 */
export function desplazamiento(raiz = document.documentElement, ventana = window) {
    const reducido = raiz.classList.contains('a11y-sin-movimiento')
        || ventana.matchMedia('(prefers-reduced-motion: reduce)').matches;

    return reducido ? 'auto' : 'smooth';
}
