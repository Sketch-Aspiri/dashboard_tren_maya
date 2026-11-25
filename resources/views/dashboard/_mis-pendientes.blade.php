{{-- Lo que le toca capturar a la estación (App\Services\Pendientes\PendientesEstacion). --}}
@php
    $chips = [
        'alerta' => ['ins-chip-error', __('Devuelta')],
        'pendiente' => ['ins-chip-alerta', __('Pendiente')],
        'revision' => ['ins-chip-info', __('En revisión')],
        'listo' => ['ins-chip-ok', __('Listo')],
    ];
    $porHacer = $pendientes->whereIn('estado', ['alerta', 'pendiente'])->count();
@endphp

<section aria-labelledby="titulo-pendientes">
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h3 id="titulo-pendientes" class="font-heading text-sm font-semibold uppercase tracking-wide text-gray-600">{{ __('Mis pendientes') }}</h3>
        <p class="text-sm text-gray-600">
            {{ $porHacer === 0 ? __('Todo al día') : trans_choice(':n por hacer|:n por hacer', $porHacer, ['n' => $porHacer]) }}
        </p>
    </div>
    <ul class="space-y-3">
        @foreach ($pendientes as $pendiente)
            @php($porAtender = in_array($pendiente['estado'], ['alerta', 'pendiente'], true))
            <li>
                <a href="{{ $pendiente['href'] }}"
                   class="flex min-h-[72px] flex-col gap-3 rounded-xl border bg-white p-4 shadow-sm shadow-brand-green/5 hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal sm:flex-row sm:items-center sm:justify-between {{ $pendiente['estado'] === 'alerta' ? 'border-red-300' : 'border-brand-green/10' }}">
                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-heading text-base font-semibold text-brand-green">{{ $pendiente['titulo'] }}</span>
                            <span class="{{ $chips[$pendiente['estado']][0] }}">{{ $chips[$pendiente['estado']][1] }}</span>
                        </span>
                        <span class="mt-1 block text-sm text-gray-600">{{ $pendiente['detalle'] }}</span>
                    </span>
                    <span class="{{ $porAtender ? 'ins-btn-primario' : 'ins-btn-secundario' }} w-full shrink-0 sm:w-36">{{ $pendiente['accion'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
