/**
 * Texto separado por tabuladores (lo que Excel, Google Sheets y LibreOffice
 * ponen en el portapapeles). Una celda con tabulador, salto de línea o
 * comillas va entre comillas, con "" para una comilla.
 */

/** @param {string[][]} filas */
export function aTsv(filas) {
    return filas
        .map((fila) => fila.map((celda) => {
            const texto = celda ?? '';
            return /[\t\n\r"]/.test(texto) ? `"${texto.replaceAll('"', '""')}"` : texto;
        }).join('\t'))
        .join('\r\n');
}

/** @returns {string[][]} */
export function deTsv(texto) {
    const filas = [];
    let fila = [];
    let celda = '';
    let entreComillas = false;
    const t = texto.replace(/\r\n?/g, '\n');

    for (let i = 0; i < t.length; i++) {
        const c = t[i];
        if (entreComillas) {
            if (c === '"' && t[i + 1] === '"') {
                celda += '"';
                i++;
            } else if (c === '"') {
                entreComillas = false;
            } else {
                celda += c;
            }
        } else if (c === '"' && celda === '') {
            entreComillas = true;
        } else if (c === '\t') {
            fila.push(celda);
            celda = '';
        } else if (c === '\n') {
            fila.push(celda);
            filas.push(fila);
            fila = [];
            celda = '';
        } else {
            celda += c;
        }
    }
    if (celda !== '' || fila.length > 0) {
        fila.push(celda);
        filas.push(fila);
    }
    return filas;
}
