{{-- Vista previa de la comprobación: las dos hojas de validación se dibujan con el mismo texto que usa el Word (ComprobacionPdfService) y lo que se escribe en cargos, nombres y firmas; el PDF completo se puede abrir aquí mismo. --}}
@php
    /** @var \App\Models\Factura $factura */
    /** @var array{datos: array<string, mixed>, hojas: list<array{titulo: string, texto: string}>, firmantes: array<string, array{cargo: string, nombre: string}>, tienePdf: bool} $vista */
@endphp

<div x-data="comprobacionFactura(@js($vista['firmantes']), @js(route('insumos.facturas.comprobacion', $factura)))" class="rounded-xl border border-brand-green/10 bg-gray-100 shadow-sm shadow-brand-green/5">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-white px-4 py-2">
        <h3 class="font-heading text-sm font-semibold text-brand-green">{{ __('Comprobación de la factura (vista previa)') }}</h3>
        <button type="button" @click="abierto = ! abierto" class="min-h-[44px] rounded-md border border-brand-teal px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist"
                x-text="abierto ? '{{ __('Ocultar') }}' : '{{ __('Abrir vista previa') }}'"></button>
    </div>

    @error('comprobacion')
        <p class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ $message }}</p>
    @enderror
    @if ($vista['datos']['sinPartidas'])
        <p class="bg-amber-50 px-4 py-2 text-sm text-amber-800">{{ __('Ningún concepto está vinculado a una partida presupuestal; la leyenda de partidas saldrá vacía.') }}</p>
    @endif

    <form x-show="abierto" x-cloak x-ref="forma" method="POST" action="{{ route('insumos.facturas.comprobacion', $factura) }}" enctype="multipart/form-data" class="space-y-4 p-3">
        @csrf
        <p class="text-xs text-gray-500">{{ __('Orden del PDF: factura, hoja de validación de la factura, factura (bloque XML) y hoja de validación del XML.') }}</p>

        @include('insumos.facturas._firmantes', ['prefijo' => 'cf'])

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="verPdf()" :disabled="cargando" class="min-h-[44px] rounded-md border border-brand-teal px-3 text-sm font-medium text-brand-teal hover:bg-brand-mist disabled:opacity-50"
                    x-text="cargando ? '{{ __('Generando…') }}' : (pdfUrl ? '{{ __('Actualizar PDF') }}' : '{{ __('Ver PDF completo') }}')"></button>
            <button type="submit" class="min-h-[44px] rounded-md bg-brand-green px-3 text-sm font-medium text-white hover:opacity-90">{{ __('Descargar PDF') }}</button>
            <button type="button" x-show="pdfUrl" x-cloak @click="pdfUrl = null" class="min-h-[44px] rounded-md px-3 text-sm text-gray-600 hover:underline">{{ __('Volver a las hojas') }}</button>
        </div>
        <p x-show="error" x-cloak x-text="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"></p>

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
</div>

@include('insumos.facturas._comprobacion-scripts')
