{{-- Pantalla de carga (resources/js/pantalla-carga.js). Debe ser hijo directo de <body>: mientras está activa, el resto
     de la página queda inerte. `data-descargas`: rutas que entregan un archivo y por eso no cambian de pantalla. --}}
<div id="app-page-loader" class="pantalla-carga" data-descargas="/(descargar|exportar|comprobante|ficha|formato)$|/insumos/consolidado$|/vacaciones/periodos/\d+/consolidado$|/asistencia/documentos/\d+/(docx|pdf)$|/asistencia/oficios-comision/\d+$|/archivo(/(xml|pdf))?$|/(archivos|fotos|evidencias)/\d+$">
    <div class="pantalla-carga-contenido">
        <div class="pantalla-carga-placa">
            <img src="{{ asset('logo.png') }}" alt="" width="792" height="480">
        </div>
        <div class="pantalla-carga-puntos" aria-hidden="true"><span></span><span></span><span></span></div>
        <button type="button" class="pantalla-carga-cerrar" data-cerrar-carga hidden>{{ __('Cerrar') }}</button>
    </div>
    <p class="sr-only" role="status" data-aviso-carga data-texto="{{ __('Cargando…') }}"></p>
</div>
