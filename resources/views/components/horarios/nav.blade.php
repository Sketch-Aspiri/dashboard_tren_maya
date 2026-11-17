@props(['activo' => 'horario'])

{{-- Pestañas del módulo de horarios. Cada una respeta su policy; en celular se desplaza de lado. --}}
@php
    $esZona = auth()->user()?->can('gestionar', \App\Models\HorarioPeriodo::class) ?? false;
    $pestanas = [
        ['horario', __('Horario'), route('horarios.index'), true],
        ['solicitudes', $esZona ? __('Solicitudes de cambio') : __('Mis solicitudes'), route('horarios.solicitudes.index'), true],
        ['crear', __('Crear horario'), route('horarios.crear.create'), $esZona],
        ['importar', __('Subir horario oficial'), route('horarios.importar.create'), $esZona],
        ['consolidados', __('Consolidado semanal'), route('horarios.consolidados.index'), $esZona],
    ];
@endphp

<nav aria-label="{{ __('Secciones de horarios') }}" {{ $attributes->merge(['class' => 'ins-card overflow-x-auto']) }}>
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
