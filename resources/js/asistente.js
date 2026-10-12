/**
 * Asistente de preguntas del dashboard
 * (resources/views/dashboard/asistente.blade.php).
 */
import { mensajesDeError, tokenCsrf } from './secciones';

const MAX_TURNOS_HISTORIAL = 4;

/**
 * @param {string} texto
 * @returns {string}
 */
function escaparHtml(texto) {
    return texto
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

/**
 * Markdown mínimo y SEGURO para las respuestas: todo el texto se escapa
 * primero y solo después se agregan etiquetas fijas (negritas, listas,
 * tablas). Nunca se inserta HTML proveniente de la respuesta.
 *
 * @param {string} texto
 * @returns {string}
 */
export function markdownSeguro(texto) {
    const lineas = escaparHtml(texto ?? '').split('\n');
    const html = [];
    let lista = false;
    let tabla = [];

    const negritas = (linea) => linea.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    const cerrarLista = () => {
        if (lista) {
            html.push('</ul>');
            lista = false;
        }
    };
    const cerrarTabla = () => {
        if (tabla.length === 0) {
            return;
        }
        const filas = tabla.filter((fila) => !/^\|?\s*:?-{2,}/.test(fila));
        const celdas = (fila) => fila.replace(/^\||\|$/g, '').split('|').map((celda) => negritas(celda.trim()));
        const [encabezado, ...cuerpo] = filas;
        html.push('<div class="overflow-x-auto"><table class="min-w-full text-xs"><thead><tr>');
        celdas(encabezado).forEach((celda) => html.push(`<th class="px-2 py-1 text-left font-semibold">${celda}</th>`));
        html.push('</tr></thead><tbody>');
        cuerpo.forEach((fila) => {
            html.push('<tr class="border-t border-brand-green/10">');
            celdas(fila).forEach((celda) => html.push(`<td class="px-2 py-1">${celda}</td>`));
            html.push('</tr>');
        });
        html.push('</tbody></table></div>');
        tabla = [];
    };

    for (const linea of lineas) {
        const recortada = linea.trim();
        if (recortada.startsWith('|')) {
            cerrarLista();
            tabla.push(recortada);
            continue;
        }
        cerrarTabla();
        if (/^[-*•]\s+/.test(recortada)) {
            if (!lista) {
                html.push('<ul class="list-disc space-y-1 ps-5">');
                lista = true;
            }
            html.push(`<li>${negritas(recortada.replace(/^[-*•]\s+/, ''))}</li>`);
            continue;
        }
        cerrarLista();
        if (recortada !== '') {
            html.push(`<p>${negritas(recortada.replace(/^#{1,6}\s+/, ''))}</p>`);
        }
    }
    cerrarLista();
    cerrarTabla();

    return html.join('');
}

/**
 * @param {string} url
 */
function asistente(url) {
    return {
        pregunta: '',
        pensando: false,
        anuncio: '',
        conversacion: [],
        formatear: markdownSeguro,
        historial() {
            return this.conversacion
                .filter((turno) => turno.respuesta && !turno.error)
                .slice(-MAX_TURNOS_HISTORIAL)
                .map((turno) => ({ pregunta: turno.pregunta, respuesta: turno.respuesta.slice(0, 6000) }));
        },
        async preguntar() {
            const pregunta = this.pregunta.trim();
            if (pregunta.length < 2 || this.pensando) {
                return;
            }
            const historial = this.historial();
            this.conversacion.push({ pregunta, respuesta: '', estado: 'Pensando…', error: '', fuentes: [] });
            const turno = this.conversacion[this.conversacion.length - 1];
            this.pregunta = '';
            this.pensando = true;
            this.anuncio = 'Pensando…';

            try {
                const respuesta = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/x-ndjson, application/json',
                        'X-CSRF-TOKEN': tokenCsrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ pregunta, historial }),
                });

                if (!respuesta.ok || !respuesta.body) {
                    const cuerpo = await respuesta.json().catch(() => null);
                    turno.error = mensajesDeError(respuesta.status, cuerpo).join(' ');
                    return;
                }

                await this.leer(respuesta.body.getReader(), turno);
            } catch {
                turno.error = 'No se pudo conectar con el asistente. Revisa tu conexión.';
            } finally {
                turno.estado = '';
                this.pensando = false;
                this.anuncio = turno.error ? 'No se obtuvo respuesta.' : 'Respuesta lista.';
                // En celular, enfocar el campo abre el teclado y tapa la respuesta.
                if (window.matchMedia('(pointer: fine)').matches) {
                    this.$nextTick(() => this.$refs.entrada?.focus());
                }
            }
        },
        async leer(lector, turno) {
            const decodificador = new TextDecoder();
            let pendiente = '';

            for (;;) {
                const { value, done } = await lector.read();
                if (done) {
                    break;
                }
                pendiente += decodificador.decode(value, { stream: true });
                const lineas = pendiente.split('\n');
                pendiente = lineas.pop() ?? '';
                lineas.forEach((linea) => this.aplicarLinea(linea, turno));
            }

            // Último evento sin salto de línea final.
            pendiente += decodificador.decode();
            this.aplicarLinea(pendiente, turno);
        },
        aplicarLinea(linea, turno) {
            if (linea.trim() === '') {
                return;
            }
            try {
                this.aplicar(JSON.parse(linea), turno);
            } catch {
                // Una línea dañada no debe tirar toda la respuesta.
            }
        },
        aplicar(evento, turno) {
            if (evento.tipo === 'texto') {
                turno.respuesta += evento.contenido;
            } else if (evento.tipo === 'estado') {
                turno.estado = evento.contenido;
            } else if (evento.tipo === 'fuentes') {
                turno.fuentes = evento.contenido;
            } else if (evento.tipo === 'error') {
                turno.error = evento.contenido;
            }
        },
    };
}

/**
 * @param {import('alpinejs').Alpine} Alpine
 */
export function registrarAsistente(Alpine) {
    Alpine.data('asistente', asistente);
}
