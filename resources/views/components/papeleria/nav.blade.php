@props(['activo' => 'solicitudes', 'anio' => null, 'mes' => null])

{{-- Pestañas del módulo de papelería; cada una respeta su policy. --}}
@php
    $periodo = array_filter(['anio' => $anio, 'mes' => $mes]);
    $esEstacion = auth()->user()?->hasRole(\App\Support\Roles::ESTACION) ?? false;
    $pestanas = [
        ['solicitudes', $esEstacion ? __('Mi solicitud') : __('Solicitudes'), route('papeleria.index', $periodo), true],
        ['catalogo', __('Catálogo'), route('papeleria.catalogo.index'), auth()->user()?->can('manage', \App\Models\CatalogoPapeleria::class)],
    ];
@endphp

<nav aria-label="{{ __('Secciones de papelería') }}" {{ $attributes->merge(['class' => 'ins-card overflow-x-auto']) }}>
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
