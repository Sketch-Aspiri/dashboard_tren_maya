/**
 * Contador de la campana de notificaciones: consulta el resumen del servidor cada minuto (y al volver a
 * la pestaña) y lo muestra como insignia. Si falla la consulta, deja el último valor sin romper la página.
 *
 * @param {import('alpinejs').Alpine} Alpine
 */
export function registrarNotificaciones(Alpine) {
    Alpine.data('notificacionesContador', (url, inicial = 0) => ({
        total: inicial,
        init() {
            this.consultar();
            setInterval(() => this.consultar(), 60000);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    this.consultar();
                }
            });
        },
        get hayPendientes() {
            return this.total > 0;
        },
        get etiqueta() {
            return this.total > 99 ? '99+' : String(this.total);
        },
        async consultar() {
            try {
                const respuesta = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!respuesta.ok) {
                    return;
                }
                const cuerpo = await respuesta.json();
                this.total = Number(cuerpo?.data?.unread ?? 0);
            } catch (e) {
                // Sin red: se conserva el valor anterior.
            }
        },
    }));
}
