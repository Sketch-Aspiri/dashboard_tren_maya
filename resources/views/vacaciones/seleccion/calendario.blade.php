@php
    $consulta = fn (array $extra = []) => array_filter(array_merge(['q' => $filtros['q'], 'estacion' => $filtros['estacion'], 'estado' => $filtros['estado']], $extra), fn ($v) => filled($v));
    $anterior = $mes > 1 ? $mes - 1 : null;
    $siguiente = $mes < 12 ? $mes + 1 : null;
    $dias = [__('Lun'), __('Mar'), __('Mié'), __('Jue'), __('Vie'), __('Sáb'), __('Dom')];
    $estilo = fn (array $e) => 'background:'.$e['color']['fondo'].';color:'.$e['color']['texto'].';border-color:'.$e['color']['borde'];
    $chip = fn (array $e) => $estilo($e).($e['estado'] === 'enviada' ? ';border-style:dashed' : '');
    $conEntradas = collect($datos['semanas'])->flatten(1)->filter(fn ($d) => $d['delMes'] && $d['entradas'] !== []);
    // Clave corta y distinta de cada estación: el calendario no depende solo del color.
    $claves = [];
    foreach ($datos['estaciones'] as $est) {
        $palabras = preg_split('/\s+/u', trim((string) $est['nombre']));
        $base = count($palabras) > 1 ? implode('', array_map(fn ($p) => mb_substr($p, 0, 1), $palabras)) : mb_substr($palabras[0], 0, 3);
        $largo = 3;
        $clave = mb_strtoupper($base);
        while (in_array($clave, $claves, true) && $largo < mb_strlen((string) $est['nombre'])) {
            $clave = mb_strtoupper(mb_substr(str_replace(' ', '', (string) $est['nombre']), 0, ++$largo));
        }
        $claves[$est['nombre']] = $clave;
    }
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-vacaciones.titulo :titulo="__('Calendario de vacaciones :anio', ['anio' => $anio])"
                             :detalle="$esZona ? __('Lo que ya eligió el personal, por estación') : auth()->user()->estacion?->nombre" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <x-vacaciones.nav activo="calendario" />

            <form method="GET" action="{{ route('vacaciones.seleccion.calendario') }}" class="ins-card ins-card-pad flex flex-wrap items-end gap-3">
                <input type="hidden" name="mes" value="{{ $mes }}">
                <div class="min-w-[min(14rem,100%)] flex-1">
                    <label for="q" class="block text-sm font-medium text-gray-700">{{ __('Buscar persona') }}</label>
                    <input id="q" type="search" name="q" value="{{ $filtros['q'] }}" maxlength="100" class="ins-campo w-full" placeholder="{{ __('Nombre o número de empleado') }}">
                </div>
                @if ($esZona)
                    <div>
                        <label for="estacion" class="block text-sm font-medium text-gray-700">{{ __('Estación') }}</label>
                        <select id="estacion" name="estacion" class="ins-campo">
                            <option value="">{{ __('Todas') }}</option>
                            @foreach ($estacionesFiltro as $e)
                                <option value="{{ $e->id }}" @selected((int) $filtros['estacion'] === $e->id)>{{ $e->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label for="estado" class="block text-sm font-medium text-gray-700">{{ __('Estado') }}</label>
                    <select id="estado" name="estado" class="ins-campo">
                        <option value="">{{ __('Enviadas y autorizadas') }}</option>
                        <option value="autorizada" @selected($filtros['estado'] === 'autorizada')>{{ __('Solo autorizadas') }}</option>
                        <option value="enviada" @selected($filtros['estado'] === 'enviada')>{{ __('Solo por revisar') }}</option>
                    </select>
                </div>
                <button type="submit" class="ins-btn-primario">{{ __('Filtrar') }}</button>
                @if (filled($filtros['q']) || filled($filtros['estacion']) || filled($filtros['estado']))
                    <a href="{{ route('vacaciones.seleccion.calendario', ['mes' => $mes]) }}" class="ins-btn-neutro">{{ __('Limpiar') }}</a>
                @endif
            </form>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    @if ($anterior)
                        <a href="{{ route('vacaciones.seleccion.calendario', $consulta(['mes' => $anterior])) }}" class="ins-btn-neutro" aria-label="{{ __('Mes anterior') }}">‹</a>
                    @endif
                    <h3 class="font-heading text-lg font-semibold text-gray-900" aria-live="polite">{{ $titulo }}</h3>
                    @if ($siguiente)
                        <a href="{{ route('vacaciones.seleccion.calendario', $consulta(['mes' => $siguiente])) }}" class="ins-btn-neutro" aria-label="{{ __('Mes siguiente') }}">›</a>
                    @endif
                </div>
                <p class="text-sm text-gray-600">{{ trans_choice(':n periodo este mes|:n periodos este mes', $datos['total'], ['n' => $datos['total']]) }}</p>
            </div>

            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-700" aria-label="{{ __('Colores por estación') }}">
                @foreach ($datos['estaciones'] as $e)
                    <li class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-3 w-3 rounded-sm border" style="{{ $estilo($e) }}" aria-hidden="true"></span><span class="font-semibold">{{ $claves[$e['nombre']] }}</span> {{ $e['nombre'] }}
                    </li>
                @endforeach
                <li class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm border border-dashed border-gray-500" aria-hidden="true"></span>{{ __('Borde punteado y «?»: por revisar') }}</li>
            </ul>

            {{-- Cuadrícula (pantallas medianas y grandes) --}}
            <div class="ins-card hidden overflow-x-auto sm:block">
                <table class="w-full min-w-[56rem] table-fixed border-collapse text-xs">
                    <caption class="sr-only">{{ __('Vacaciones de :mes por día', ['mes' => $titulo]) }}</caption>
                    <thead>
                        <tr>
                            @foreach ($dias as $i => $d)
                                <th scope="col" class="border-b border-gray-200 px-2 py-2 text-left font-semibold {{ $i > 4 ? 'text-gray-600' : 'text-gray-700' }}">{{ $d }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datos['semanas'] as $semana)
                            <tr>
                                @foreach ($semana as $dia)
                                    <td class="h-28 align-top border border-gray-100 p-1 {{ ! $dia['delMes'] ? 'bg-brand-mist' : ($dia['finDeSemana'] ? 'bg-gray-50' : '') }}">
                                        <div class="mb-1 flex items-center justify-between">
                                            <span class="{{ $dia['hoy'] ? 'rounded bg-brand-green px-1 text-white' : 'text-gray-600' }} font-semibold">{{ $dia['dia'] }}@if ($dia['hoy'])<span class="sr-only"> ({{ __('hoy') }})</span>@endif</span>
                                            @if (count($dia['entradas']) > 0)
                                                <span class="text-[0.625rem] text-gray-600"><span aria-hidden="true">{{ count($dia['entradas']) }}</span><span class="sr-only">{{ trans_choice(':n persona|:n personas', count($dia['entradas']), ['n' => count($dia['entradas'])]) }}</span></span>
                                            @endif
                                        </div>
                                        @if ($dia['inhabil'])
                                            <p class="mb-1 break-words text-[0.625rem] italic text-gray-600">{{ $dia['inhabil'] }}</p>
                                        @endif
                                        <ul class="space-y-0.5">
                                            @foreach (array_slice($dia['entradas'], 0, 4) as $e)
                                                <li class="break-words rounded border-l-4 px-1 py-px" style="{{ $chip($e) }}"><span class="font-semibold" aria-hidden="true">{{ $claves[$e['estacion']] ?? '' }}@if ($e['estado'] === 'enviada') ?@endif</span> {{ $e['nombre'] }}<span class="sr-only">, {{ $e['estacion'] }}, {{ $e['periodo'] }}, {{ $e['estado'] === 'autorizada' ? __('autorizada') : __('por revisar') }}</span></li>
                                            @endforeach
                                            @if (count($dia['entradas']) > 4)
                                                <li class="text-[0.625rem] font-semibold text-gray-600">{{ __('+:n más', ['n' => count($dia['entradas']) - 4]) }}</li>
                                            @endif
                                        </ul>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Agenda (celular): solo los días con vacaciones --}}
            <div class="space-y-2 sm:hidden">
                @forelse ($conEntradas as $dia)
                    <section class="ins-card ins-card-pad">
                        <h4 class="text-sm font-semibold text-gray-900">{{ \Carbon\CarbonImmutable::parse($dia['fecha'])->locale('es')->translatedFormat('l j \d\e F') }}</h4>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($dia['entradas'] as $e)
                                <li class="rounded border-l-4 px-2 py-1" style="{{ $chip($e) }}">{{ $e['nombre'] }} <span class="text-xs">· {{ $e['estacion'] }}@if ($e['estado'] === 'enviada') · {{ __('por revisar') }}@endif</span></li>
                            @endforeach
                        </ul>
                    </section>
                @empty
                    <p class="ins-card ins-card-pad text-sm text-gray-600">{{ __('Nadie tiene vacaciones elegidas este mes con estos filtros.') }}</p>
                @endforelse
            </div>

            {{-- Detalle del mes: quién y cuándo (también sirve para buscar a una persona) --}}
            <section class="ins-card ins-card-pad space-y-2">
                <h3 class="font-heading text-base font-semibold text-gray-900">{{ __('Periodos de :mes', ['mes' => $titulo]) }}</h3>
                @if ($datos['personas'] === [])
                    <p class="text-sm text-gray-600">{{ __('Nadie tiene vacaciones elegidas este mes con estos filtros.') }}</p>
                @else
                    <div class="ins-tabla-marco">
                        <table class="ins-tabla">
                            <caption class="sr-only">{{ __('Periodos de :mes', ['mes' => $titulo]) }}</caption>
                            <thead><tr><th scope="col">{{ __('Persona') }}</th><th scope="col">{{ __('Estación') }}</th><th scope="col">{{ __('Periodo') }}</th><th scope="col">{{ __('Días') }}</th><th scope="col">{{ __('Estado') }}</th></tr></thead>
                            <tbody>
                                @foreach ($datos['personas'] as $p)
                                    <tr>
                                        <th scope="row" class="px-3 py-2.5 text-left align-top font-medium text-gray-900 sm:px-4">{{ $p['nombre'] }}<span class="block text-xs font-normal text-gray-500">{{ $p['numero'] }}</span></th>
                                        <td><span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm border" style="{{ $estilo($p) }}"></span>{{ $p['estacion'] }}</span></td>
                                        <td>{{ $p['periodo'] }}</td>
                                        <td>{{ $p['dias'] }}</td>
                                        <td><span class="{{ $p['estado'] === 'autorizada' ? 'ins-chip-ok' : 'ins-chip-info' }}">{{ $p['estado'] === 'autorizada' ? __('Autorizada') : __('Por revisar') }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
