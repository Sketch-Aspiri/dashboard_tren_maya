@props(['titulo', 'detalle' => null, 'volver' => null, 'volverTexto' => null, 'seccion' => null])

{{-- Encabezado de las vistas de insumos: de dónde vienes (enlace de regreso),
     el título y, si aplica, una línea de detalle. --}}
<div class="min-w-0">
    <p class="text-xs font-semibold uppercase tracking-wide text-brand-teal">
        @if ($volver)
            <a href="{{ $volver }}" class="inline-flex items-center gap-1 rounded hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                <span aria-hidden="true">←</span> {{ $volverTexto ?? __('Volver') }}
            </a>
        @else
            {{ __('Recursos materiales') }} · {{ $seccion ?? __('Fondo rotatorio') }}
        @endif
    </p>
    <h2 class="mt-0.5 truncate font-heading text-xl font-semibold leading-tight text-brand-green">{{ $titulo }}</h2>
    @if ($detalle)
        <p class="mt-0.5 truncate text-sm text-gray-500">{{ $detalle }}</p>
    @endif
</div>
