/**
 * Temayin: preguntas frecuentes precargadas (sin servidor), igual que en el
 * sistema de Tickets. Las preguntas, respuestas y enlaces llegan ya filtrados
 * por rol en `data-faqs` (JSON plano) y se pintan con x-text / x-bind, nunca
 * como HTML.
 */
export function registrarTemayin(Alpine) {
    Alpine.data('temayin', () => ({
        open: false,
        activeId: '',
        faqs: {},
        // Burbuja "recuerda crear un ticket a Soporte": visible con el panel
        // cerrado hasta que se descarta (se recuerda en la sesión del navegador).
        bubbleDismissed: false,

        init() {
            try {
                this.bubbleDismissed = window.sessionStorage.getItem('temayin-bubble-dismissed') === '1';
            } catch {
                this.bubbleDismissed = false;
            }

            try {
                this.faqs = JSON.parse(this.$el.dataset.faqs ?? '{}');
            } catch {
                this.faqs = {};
            }
        },

        get hasActive() {
            return this.activeId !== '' && this.activeId in this.faqs;
        },

        get hasNoActive() {
            return !this.hasActive;
        },

        get activeQuestion() {
            return this.hasActive ? this.faqs[this.activeId].q : '';
        },

        get activeAnswer() {
            return this.hasActive ? this.faqs[this.activeId].a : '';
        },

        get activeUrl() {
            return this.hasActive ? this.faqs[this.activeId].url ?? '' : '';
        },

        get activeLink() {
            return this.hasActive ? this.faqs[this.activeId].enlace ?? '' : '';
        },

        get hasLink() {
            return this.activeUrl !== '' && this.activeLink !== '';
        },

        get showBubble() {
            return !this.open && !this.bubbleDismissed;
        },

        dismissBubble() {
            this.bubbleDismissed = true;

            try {
                window.sessionStorage.setItem('temayin-bubble-dismissed', '1');
            } catch {
                // Almacenamiento bloqueado: basta con ocultarla en esta página.
            }
        },

        get toggleExpanded() {
            return this.open ? 'true' : 'false';
        },

        toggle() {
            if (this.open) {
                this.close();
                return;
            }

            this.open = true;
            this.$nextTick(() => this.$refs.panel.focus());
        },

        close() {
            if (!this.open) {
                return;
            }

            this.open = false;
            this.activeId = '';
            this.$nextTick(() => this.$refs.toggle.focus());
        },

        ask(event) {
            this.activeId = event.currentTarget.dataset.id ?? '';
            this.$nextTick(() => this.$refs.panel.focus());
        },

        back() {
            this.activeId = '';
            this.$nextTick(() => this.$refs.panel.focus());
        },
    }));
}
