@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\HorarioConsolidado> $consolidados */
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-horarios.titulo :titulo="__('Consolidado semanal')" :detalle="__('Para enviar a Coordinación cada lunes')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-horarios.nav activo="consolidados" />

            <x-insumos.avisos />

            <p class="text-sm text-gray-600">{{ __('Cada lunes a las :hora (hora de Cancún) se genera solo el consolidado de la semana que empieza: horario vigente de todas las estaciones, con los cambios autorizados ya aplicados, y una hoja con los cambios.', ['hora' => config('horarios.consolidado_hora')]) }}</p>

            <form method="POST" action="{{ route('horarios.consolidados.generar') }}" class="ins-card ins-card-pad flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label for="semana" class="ins-etiqueta">{{ __('Semana que incluye el día') }}</label>
                    <input id="semana" name="semana" type="date" value="{{ $lunes->toDateString() }}" class="ins-campo mt-1">
                </div>
                <button type="submit" class="ins-btn-secundario">{{ __('Generar ahora') }}</button>
            </form>

            @forelse ($consolidados as $c)
                @if ($loop->first)
                    <div class="ins-tabla-marco">
                        <table class="ins-tabla">
                            <thead><tr><th scope="col">{{ __('Semana') }}</th><th scope="col" class="!text-right">{{ __('Turnos') }}</th><th scope="col" class="!text-right">{{ __('Cambios') }}</th><th scope="col">{{ __('Generado') }}</th><th scope="col"><span class="sr-only">{{ __('Descargar') }}</span></th></tr></thead>
                            <tbody>
                @endif
                                <tr>
                                    <td class="fuerte">{{ $c->semana_inicio->format('d/m/Y') }} – {{ $c->semana_inicio->addDays(6)->format('d/m/Y') }}</td>
                                    <td class="num">{{ number_format($c->turnos) }}</td>
                                    <td class="num">{{ $c->cambios }}</td>
                                    <td>{{ $c->updated_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }} · {{ $c->generadoPor?->name ?? __('Automático') }}</td>
                                    <td class="num"><a href="{{ route('horarios.consolidados.descargar', $c) }}" class="ins-btn-secundario !min-h-[36px] !px-3">{{ __('Descargar Excel') }}</a></td>
                                </tr>
                @if ($loop->last)
                            </tbody>
                        </table>
                    </div>
                @endif
            @empty
                <div class="ins-card ins-card-pad text-center text-sm text-gray-600">{{ __('Aún no hay consolidados generados.') }}</div>
            @endforelse
        </div>
    </div>
</x-app-layout>
