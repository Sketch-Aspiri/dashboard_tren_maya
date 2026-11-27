// Aplica las preferencias de accesibilidad antes de pintar la pagina, para que no salte el tamano ni el contraste.
// MISMO ARCHIVO en Portal, Dashboard y Tickets. Es un archivo propio (no un script en linea) por la CSP de Tickets y
// se carga de forma sincrona en el <head>. La preferencia vive en la cookie "a11y" del dominio comun (cambiarla en un
// sistema la cambia en los tres) y en localStorage. El panel esta en resources/js/accesibilidad.js.
// Valores, separados por punto: t112 | t125 | t150 (letra), c (contraste), m (sin movimiento), e (enlaces subrayados);
// "n" = elegido sin nada activo. Sin preferencia guardada se sigue la del sistema (contraste).
(function () {
    var valor = null;
    var cookie = document.cookie.match(/(?:^|; )a11y=([a-z0-9.]{1,40})(?:;|$)/);
    if (cookie) {
        valor = cookie[1];
    } else {
        try {
            valor = localStorage.getItem('a11y');
        } catch (e) {
            valor = null;
        }
    }

    var partes = typeof valor === 'string' ? valor.split('.') : [];
    var tiene = function (parte) {
        return partes.indexOf(parte) !== -1;
    };
    var clases = document.documentElement.classList;

    ['112', '125', '150'].forEach(function (tamano) {
        clases.toggle('a11y-texto-' + tamano, tiene('t' + tamano));
    });
    clases.toggle('a11y-contraste', valor ? tiene('c') : window.matchMedia('(prefers-contrast: more)').matches);
    clases.toggle('a11y-sin-movimiento', tiene('m'));
    clases.toggle('a11y-subrayado', tiene('e'));
})();
