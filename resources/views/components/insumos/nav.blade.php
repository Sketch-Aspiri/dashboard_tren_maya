@props(['activo' => 'solicitudes', 'anio' => null, 'mes' => null])

{{-- Navegación del módulo de insumos: las mismas pestañas en todas sus
     vistas, conservando el mes que se está viendo. Cada pestaña respeta su
     policy; en celular se desplaza de lado. --}}
@php
    $periodo = array_filter(['anio' => $anio, 'mes' => $mes]);
    $esEstacion = auth()->user()?->hasRole(\App\Support\Roles::ESTACION) ?? false;
    $pestanas = [
        ['solicitudes', $esEstacion ? __('Mi solicitud') : __('Solicitudes'), route('insumos.index', $periodo), true],
        ['cotizaciones', __('Cotizaciones'), route('insumos.cotizaciones.index', $periodo), auth()->user()?->can('manage', \App\Models\Cotizacion::class)],
        ['facturas', __('Facturas'), route('insumos.facturas.index', $periodo), auth()->user()?->can('manage', \App\Models\Factura::class)],
        ['coordinacion', __('Coordinación'), route('insumos.coordinacion.index', $periodo), auth()->user()?->can('consolidate', \App\Models\SolicitudInsumo::class)],
        ['inventario', __('Inventario'), route('insumos.inventario.index'), true],
        ['catalogo', __('Catálogo'), route('insumos.catalogo.index'), auth()->user()?->can('manage', \App\Models\CatalogoInsumo::class)],
        ['proveedores', __('Proveedores e IVA'), route('insumos.proveedores.index'), auth()->user()?->can('manage', \App\Models\Proveedor::class)],
    ];
@endphp

<nav aria-label="{{ __('Secciones de insumos') }}" {{ $attributes->merge(['class' => 'ins-card overflow-x-auto']) }}>
    <ul class="flex min-w-max items-stretch gap-1 px-2">
        @foreach ($pestanas as [$clave, $texto, $url, $visible])
            @continue(! $visible)
            <li>
                <a href="{{ $url }}" @if ($activo === $clave) aria-current="page" @endif
                   class="inline-flex min-h-[48px] items-center border-b-2 px-3 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-teal {{ $activo === $clave ? 'border-brand-green text-brand-green' : 'border-transparent text-gray-600 hover:border-brand-mint hover:text-brand-green' }}">
                    {{ $texto }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
