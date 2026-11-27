/**
 * A qué elemento de una lista de resultados lleva una tecla, partiendo del campo de búsqueda.
 *
 * @param {string} tecla  KeyboardEvent.key
 * @param {number} actual índice con el foco; -1 si el foco está en el campo
 * @param {number} total  cuántos resultados hay
 * @returns {number|null} índice destino, -1 para volver al campo, o null si la tecla no se atiende
 */
export function siguienteEnLista(tecla, actual, total) {
    if (total === 0) {
        return null;
    }
    if (tecla === 'ArrowDown') {
        return actual + 1 >= total ? 0 : actual + 1;
    }
    if (tecla === 'ArrowUp') {
        return actual === -1 ? total - 1 : actual - 1;
    }
    // Inicio y Fin solo dentro de la lista: en el campo mueven el cursor.
    if (actual !== -1 && (tecla === 'Home' || tecla === 'End')) {
        return tecla === 'Home' ? 0 : total - 1;
    }

    return null;
}
