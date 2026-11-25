/**
 * Aviso del navegador antes de perder una captura. Se activa en los formularios con `data-aviso-cambios`
 * (con valor "1" la página ya llega con datos sin guardar, p. ej. tras un error de validación).
 * Guardar ese mismo formulario no avisa; salir, cambiar de mes o enviar otro formulario sí.
 *
 * @param {Document} doc
 * @param {Window} win
 */
export function registrarCambiosSinGuardar(doc, win) {
    let guardados = new WeakSet();

    const marcar = (evento) => {
        const formulario = evento.target.closest?.('form[data-aviso-cambios]');
        if (formulario) {
            formulario.dataset.avisoCambios = '1';
            guardados.delete(formulario);
        }
    };

    doc.addEventListener('input', marcar);
    doc.addEventListener('change', marcar);

    // En fase de burbuja: si otro manejador ya canceló el envío (confirm), el aviso se conserva.
    doc.addEventListener('submit', (evento) => {
        if (!evento.defaultPrevented && evento.target.dataset?.avisoCambios !== undefined) {
            guardados.add(evento.target);
        }
    });

    win.addEventListener('beforeunload', (evento) => {
        const pendiente = Array.from(doc.querySelectorAll('form[data-aviso-cambios]'))
            .some((formulario) => formulario.dataset.avisoCambios === '1' && !guardados.has(formulario));

        if (pendiente) {
            evento.preventDefault();
            evento.returnValue = '';
        }
    });

    // Al volver con el botón "atrás" la página sigue en memoria: se vuelve a proteger.
    win.addEventListener('pageshow', () => (guardados = new WeakSet()));
}
