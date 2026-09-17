@php
    /** @var \Carbon\CarbonImmutable $fecha */
    /** @var \Illuminate\Support\Collection<int, array{estacion: \App\Models\Estacion, total_roster: int, total_capturado: int, capturado: bool}> $resumen */
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Asistencia Zona Oriente') }} — {{ __('¿Quién ya capturó hoy?') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="GET" action="{{ route('asistencia.zona.index') }}" class="flex flex-wrap items-end gap-2">
                    <div class="max-w-xs">
                        <x-input-label for="fecha-zona" :value="__('Ver otra fecha')" />
                        <x-text-input id="fecha-zona" name="fecha" type="date" class="block mt-1 w-full"
                                      value="{{ $fecha->toDateString() }}" />
                    </div>
                    <x-secondary-button type="submit">{{ __('Ver esta fecha') }}</x-secondary-button>
                </form>
                <p class="mt-3 text-sm text-gray-600">
                    {{ __('Fecha mostrada') }}: <span class="font-medium text-brand-green">{{ $fecha->translatedFormat('d \d\e F \d\e Y') }}</span>
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($resumen as $fila)
                    @php
                        $estacion = $fila['estacion'];
                        $sinPersonal = $fila['total_roster'] === 0;
                        $badgeClass = match (true) {
                            $sinPersonal => 'bg-gray-100 text-gray-600',
                            $fila['capturado'] => 'bg-brand-mist text-brand-green',
                            default => 'bg-amber-100 text-amber-800',
                        };
                        $badgeLabel = match (true) {
                            $sinPersonal => __('Sin personal'),
                            $fila['capturado'] => __('Capturado'),
                            default => __('Pendiente'),
                        };
                    @endphp
                    <a href="{{ route('asistencia.zona.show', ['estacion' => $estacion->id, 'fecha' => $fecha->toDateString()]) }}"
                       class="block rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 transition hover:border-brand-teal/40 hover:shadow-md sm:p-6">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-heading text-base font-semibold text-brand-green">
                                {{ $estacion->nombre }}
                            </h3>
                            <span class="inline-flex min-h-[28px] items-center rounded-full px-3 py-1 text-xs font-semibold {{ $badgeClass }}">
                                {{ $badgeLabel }}
                            </span>
                        </div>
                        <p class="mt-3 text-sm text-gray-600">
                            {{ __(':capturado / :total capturados', ['capturado' => $fila['total_capturado'], 'total' => $fila['total_roster']]) }}
                        </p>
                    </a>
                @endforeach
            </div>

            @if ($resumen->isEmpty())
                <p class="text-sm text-gray-500">{{ __('No hay estaciones operativas configuradas.') }}</p>
            @endif

        </div>
    </div>
</x-app-layout>
