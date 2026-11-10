{{-- Vista previa de la comprobación: las dos hojas de validación se dibujan con el mismo texto que usa el Word (ComprobacionPdfService) y lo que se escribe en cargos, nombres y firmas; el PDF completo se puede abrir aquí mismo. --}}
@php
    /** @var \App\Models\Factura $factura */
    /** @var array{datos: array<string, mixed>, hojas: list<array{titulo: string, texto: string}>, firmantes: array<string, array{cargo: string, nombre: string}>, tienePdf: bool} $vista */
    $bloqueada = $factura->verificacion_sat === 'CANCELADO' || ! $vista['tienePdf'];
@endphp

<section x-data="comprobacionFactura(@js($vista['firmantes']), @js(route('insumos.facturas.comprobacion', $factura)))" class="ins-card overflow-hidden" aria-labelledby="comprobacion-titulo">
    <div class="flex flex-wrap items-center justify-between gap-3 p-4 sm:px-6">
        <div>
            <h3 id="comprobacion-titulo" class="ins-titulo">{{ __('Comprobación en PDF') }}</h3>
            <p class="ins-ayuda mt-0.5">{{ __('Factura, hoja de validación de la factura, factura (bloque XML) y hoja de validación del XML.') }}</p>
        </div>
        <button type="button" @click="abierto = ! abierto" :aria-expanded="abierto.toString()" aria-controls="comprobacion-forma" class="ins-btn-secundario" @disabled($bloqueada)
                x-text="abierto ? '{{ __('Ocultar') }}' : '{{ __('Abrir vista previa') }}'"></button>
    </div>

    @error('comprobacion')
        <p class="border-t border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-6" role="alert">{{ $message }}</p>
    @enderror
    @unless ($vista['tienePdf'])
        <p class="border-t border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 sm:px-6">{{ __('Falta el PDF de la factura: súbelo abajo para poder generar la comprobación.') }}</p>
    @endunless
    @if ($vista['datos']['sinPartidas'])
        <p class="border-t border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 sm:px-6">{{ __('Ningún concepto está vinculado a una partida presupuestal; la leyenda de partidas saldrá vacía.') }}</p>
    @endif

    <form id="comprobacion-forma" x-show="abierto" x-cloak x-ref="forma" method="POST" action="{{ route('insumos.facturas.comprobacion', $factura) }}" enctype="multipart/form-data"
          class="space-y-4 border-t border-gray-100 bg-gray-50 p-4 sm:p-6">
        @csrf

        @include('insumos.facturas._firmantes', ['prefijo' => 'cf'])

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="verPdf()" :disabled="cargando" class="ins-btn-secundario"
                    x-text="cargando ? '{{ __('Generando…') }}' : (pdfUrl ? '{{ __('Actualizar PDF') }}' : '{{ __('Ver PDF completo') }}')"></button>
            <button type="submit" class="ins-btn-primario">{{ __('Descargar PDF') }}</button>
            <button type="button" x-show="pdfUrl" x-cloak @click="pdfUrl = null" class="ins-enlace">{{ __('Volver a las hojas') }}</button>
        </div>
        <p x-show="error" x-cloak x-text="error" class="ins-aviso-error" role="alert"></p>

        <div x-show="pdfUrl" x-cloak>
            <iframe :src="pdfUrl" title="{{ __('Comprobación en PDF') }}" class="h-[80vh] w-full rounded-md border border-gray-300 bg-white"></iframe>
        </div>

        <div x-show="! pdfUrl" class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($vista['hojas'] as $hoja)
                <div class="overflow-x-auto">
                    <div style="width: 612px; max-width: 100%; min-height: 560px; box-sizing: border-box; padding: 40px 36px; margin: 0 auto; background: #fff; color: #000; font-family: 'Noto Sans', Arial, sans-serif; font-size: 11px; box-shadow: 0 1px 4px rgb(0 0 0 / 0.25);">
                        <p style="text-align: justify; line-height: 2;">{{ $hoja['texto'] }}</p>
                        <p style="margin-top: 14px;">Libramiento de Chetumal km 15, Municipio de Othón P. Blanco, Chetumal, Q. Roo, CP 77962.</p>
                        <p style="margin-top: 28px; text-align: center;">{{ $hoja['titulo'] }}</p>
                        <div style="display: flex; justify-content: space-between; gap: 16px; margin-top: 28px; text-align: center;">
                            @foreach (['recibio' => 'Recibió:', 'autorizo' => 'Autorizó.'] as $clave => $encabezado)
                                <div style="flex: 1;">
                                    <p style="font-weight: 700;">{{ $encabezado }}</p>
                                    <p style="font-weight: 700;" x-text="f.{{ $clave }}.cargo || cargoBase.{{ $clave }}"></p>
                                    <div style="height: 52px; display: flex; align-items: flex-end; justify-content: center;">
                                        <img x-show="firmas.{{ $clave }}" x-cloak :src="firmas.{{ $clave }}" alt="" style="max-height: 52px; max-width: 150px;">
                                    </div>
                                    <p>__________________________</p>
                                    <p x-text="f.{{ $clave }}.nombre || nombreBase.{{ $clave }}"></p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </form>
</section>

@include('insumos.facturas._comprobacion-scripts')
