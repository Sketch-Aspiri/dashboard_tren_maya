@php
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $fechaCorta = fn (int $dia, int $mes) => $dia.' de '.mb_strtolower($meses[$mes]);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-teal">{{ __('RR.HH.') }}</p>
            <h1 class="mt-0.5 truncate font-heading text-xl font-semibold leading-tight text-brand-green">{{ __('Cumpleaños') }}</h1>
            <p class="mt-0.5 truncate text-sm text-gray-500">{{ __('Personal activo de la zona · :n personas', ['n' => $total]) }}</p>
        </div>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <section class="ins-card ins-card-pad space-y-2" aria-labelledby="hoy-titulo">
                <h3 id="hoy-titulo" class="ins-titulo">{{ __('Hoy') }} · {{ $fechaCorta($hoy->day, $hoy->month) }}</h3>
                @forelse ($deHoy as $c)
                    <p class="text-base text-gray-900">🎂 <span class="font-semibold">{{ $c['nombre'] }}</span> <span class="text-sm text-gray-500">· {{ $c['estacion'] ?? '—' }}</span></p>
                @empty
                    <p class="text-sm text-gray-600">{{ __('Hoy no cumple años nadie.') }}</p>
                @endforelse
            </section>

            <section class="ins-card ins-card-pad space-y-2" aria-labelledby="prox-titulo">
                <h3 id="prox-titulo" class="ins-titulo">{{ __('Próximos 30 días') }}</h3>
                @forelse ($proximos as $p)
                    <p class="text-sm text-gray-900"><span class="inline-block w-28 font-semibold text-brand-green">{{ $fechaCorta($p['fecha']->day, $p['fecha']->month) }}</span> {{ $p['cumple']['nombre'] }} <span class="text-gray-500">· {{ $p['cumple']['estacion'] ?? '—' }}</span></p>
                @empty
                    <p class="text-sm text-gray-600">{{ __('No hay cumpleaños en los próximos 30 días.') }}</p>
                @endforelse
            </section>

            <form method="GET" action="{{ route('cumpleanos.index') }}" class="ins-card ins-card-pad flex flex-wrap items-end gap-3">
                <div>
                    <label for="mes" class="ins-etiqueta">{{ __('Mes') }}</label>
                    <select id="mes" name="mes" class="ins-campo mt-1">
                        @foreach ($meses as $n => $nombre) <option value="{{ $n }}" @selected($n === $mes)>{{ $nombre }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label for="estacion" class="ins-etiqueta">{{ __('Estación') }}</label>
                    <select id="estacion" name="estacion" class="ins-campo mt-1">
                        <option value="">{{ __('Todas') }}</option>
                        @foreach ($estaciones as $e) <option value="{{ $e->id }}" @selected((int) request('estacion') === $e->id)>{{ $e->nombre }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label for="q" class="ins-etiqueta">{{ __('Buscar') }}</label>
                    <input id="q" name="q" type="search" maxlength="100" value="{{ request('q') }}" class="ins-campo mt-1" placeholder="{{ __('Nombre') }}">
                </div>
                <button type="submit" class="ins-btn-neutro">{{ __('Ver') }}</button>
                @if ($busqueda) <a href="{{ route('cumpleanos.index', ['mes' => $mes]) }}" class="text-sm font-semibold text-brand-green underline">{{ __('Quitar filtros') }}</a> @endif
            </form>

            <section class="space-y-2" aria-labelledby="mes-titulo">
                <h3 id="mes-titulo" class="ins-titulo">{{ $meses[$mes] }}</h3>
                <div class="ins-tabla-marco">
                    <table class="ins-tabla">
                        <thead><tr><th scope="col">{{ __('Día') }}</th><th scope="col">{{ __('Nombre') }}</th><th scope="col">{{ __('Estación') }}</th></tr></thead>
                        <tbody>
                            @forelse ($delMes as $c)
                                <tr>
                                    <td class="fuerte">{{ $c['dia'] }}</td>
                                    <td>{{ $c['nombre'] }}</td>
                                    <td>{{ $c['estacion'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-gray-500">{{ __('Sin cumpleaños con ese filtro en este mes.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
