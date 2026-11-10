{{-- Alpine de la vista previa de la comprobación (ficha de la factura y PDF del mes). --}}
@once
    @push('scripts')
        <script>
            document.addEventListener('alpine:init', () => {
                const comprobacion = (base, url) => ({
                    abierto: false,
                    cargando: false,
                    error: '',
                    pdfUrl: null,
                    f: { recibio: { cargo: '', nombre: '' }, autorizo: { cargo: '', nombre: '' } },
                    cargoBase: { recibio: base.recibio.cargo, autorizo: base.autorizo.cargo },
                    nombreBase: { recibio: base.recibio.nombre, autorizo: base.autorizo.nombre },
                    firmas: { recibio: null, autorizo: null },

                    elegirFirma(clave, evento) {
                        const archivo = evento.target.files[0];
                        if (this.firmas[clave]) URL.revokeObjectURL(this.firmas[clave]);
                        this.firmas[clave] = archivo ? URL.createObjectURL(archivo) : null;
                    },

                    async verPdf() {
                        this.cargando = true;
                        this.error = '';
                        try {
                            const datos = new FormData(this.$refs.forma);
                            datos.append('ver', '1');
                            const respuesta = await fetch(url, { method: 'POST', body: datos, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                            if (! respuesta.ok) {
                                const cuerpo = await respuesta.json().catch(() => ({}));
                                throw new Error(cuerpo.message || '{{ __('No se pudo generar el PDF.') }}');
                            }
                            if (this.pdfUrl) URL.revokeObjectURL(this.pdfUrl);
                            this.pdfUrl = URL.createObjectURL(await respuesta.blob());
                        } catch (e) {
                            this.error = e.message;
                        } finally {
                            this.cargando = false;
                        }
                    },
                });

                Alpine.data('comprobacionFactura', comprobacion);
                Alpine.data('comprobacionMes', comprobacion);
            });
        </script>
    @endpush
@endonce
