@props(['titulo', 'detalle' => null])

{{-- Encabezado de las vistas de Vacaciones (RR.HH.). --}}
<div class="min-w-0">
    <p class="text-xs font-semibold uppercase tracking-wide text-brand-teal">{{ __('RR.HH.') }} · {{ __('Rol de vacaciones') }}</p>
    <h1 class="mt-0.5 break-words font-heading text-xl font-semibold leading-tight text-brand-green">{{ $titulo }}</h1>
    @if ($detalle)
        <p class="mt-0.5 break-words text-sm text-gray-500">{{ $detalle }}</p>
    @endif
</div>
