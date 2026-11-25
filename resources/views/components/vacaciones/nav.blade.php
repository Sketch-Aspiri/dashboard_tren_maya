@props(['activo' => 'rol'])

{{-- Pestañas del módulo de vacaciones. Cada una respeta su policy; en celular se desplaza de lado. --}}
@php
    $esZona = auth()->user()?->can('gestionar', \App\Models\VacacionPeriodo::class) ?? false;
    $pestanas = [
        ['rol', __('Rol de vacaciones'), route('vacaciones.index'), true],
        ['solicitudes', $esZona ? __('Solicitudes de cambio') : __('Mis solicitudes'), route('vacaciones.solicitudes.index'), true],
        ['mias', __('Mis vacaciones'), route('vacaciones.mi.show'), auth()->user()?->empleado_id !== null],
        ['importar', __('Subir rol'), route('vacaciones.importar.create'), $esZona],
        ['seleccion', __('Selección :anio', ['anio' => config('vacaciones.anio_seleccion')]), route('vacaciones.seleccion.revision'), true],
        ['calendario', __('Calendario'), route('vacaciones.seleccion.calendario'), auth()->user()?->can('viewAny', \App\Models\VacacionPlan::class)],
        ['catalogo', __('Catálogo :anio', ['anio' => config('vacaciones.anio_seleccion')]), route('vacaciones.seleccion.catalogo'), $esZona],
    ];
@endphp

<nav aria-label="{{ __('Secciones de vacaciones') }}" {{ $attributes->merge(['class' => 'ins-card overflow-x-auto']) }}>
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
