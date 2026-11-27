@php
    /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\VacacionPersona>> $grupos */
    $fmt = fn ($f) => $f?->format('d/m/Y');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-vacaciones.titulo :titulo="__('Rol de vacaciones')" :detalle="$periodo ? __('Año :anio · versión :v', ['anio' => $periodo->anio, 'v' => $periodo->version]) : null" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-vacaciones.nav activo="rol" />

            <x-insumos.avisos />

            @if ($esZona && $pendientesZona > 0)
                <p class="ins-aviso-info">
                    {{ trans_choice('Hay :n envío de cambios por autorizar.|Hay :n envíos de cambios por autorizar.', $pendientesZona, ['n' => $pendientesZona]) }}
                    <a href="{{ route('vacaciones.solicitudes.index') }}" class="font-semibold underline">{{ __('Ver solicitudes') }}</a>
                </p>
            @endif

            @if ($periodo === null)
                <div class="ins-card ins-card-pad text-sm text-gray-600">
                    {{ __('Todavía no se publica el rol de vacaciones.') }}
                    @if ($esZona) <a href="{{ route('vacaciones.importar.create') }}" class="font-semibold text-brand-green underline">{{ __('Subir el rol') }}</a> @endif
                </div>
            @else
                <form method="GET" action="{{ route('vacaciones.index') }}" class="ins-card ins-card-pad flex flex-wrap items-end gap-3">
                    @if ($periodos->count() > 1)
                        <div>
                            <label for="anio" class="ins-etiqueta">{{ __('Año') }}</label>
                            <select id="anio" name="anio" class="ins-campo mt-1">
                                @foreach ($periodos as $p) <option value="{{ $p->anio }}" @selected($p->id === $periodo->id)>{{ $p->anio }}</option> @endforeach
                            </select>
                        </div>
                    @endif
                    @if ($esZona)
                        <div>
                            <label for="estacion" class="ins-etiqueta">{{ __('Estación') }}</label>
                            <select id="estacion" name="estacion" class="ins-campo mt-1">
                                <option value="">{{ __('Todas') }}</option>
                                @foreach ($estaciones as $e) <option value="{{ $e->id }}" @selected((int) request('estacion') === $e->id)>{{ $e->nombre }}</option> @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <label for="q" class="ins-etiqueta">{{ __('Buscar') }}</label>
                        <input id="q" name="q" type="search" maxlength="100" value="{{ request('q') }}" class="ins-campo mt-1" placeholder="{{ __('Nombre o No. Empleado') }}">
                    </div>
                    @if ($editar) <input type="hidden" name="editar" value="1"> @endif
                    <button type="submit" class="ins-btn-neutro">{{ __('Filtrar') }}</button>
                    <div class="ml-auto flex flex-wrap gap-2">
                        @if ($puedeEditar)
                            <a href="{{ route('vacaciones.index', array_merge(request()->except('editar'), $editar ? [] : ['editar' => 1])) }}" class="{{ $editar ? 'ins-btn-neutro' : 'ins-btn-primario' }}">{{ $editar ? __('Salir de edición') : ($esZona ? __('Editar fechas') : __('Pedir cambios')) }}</a>
                        @endif
                        @if ($esZona)
                            <a href="{{ route('vacaciones.consolidado.descargar', $periodo) }}" class="ins-btn-neutro">{{ __('Descargar Excel') }}</a>
                        @endif
                    </div>
                </form>

                @if ($grupos->isEmpty())
                    <div class="ins-card ins-card-pad text-sm text-gray-600">{{ __('No hay personas para mostrar con ese filtro.') }}</div>
                @endif

                @if ($editar)
                    <form method="POST" action="{{ route('vacaciones.tramos.cambiar', $periodo) }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        @error('tramos') <p class="ins-aviso-error">{{ $message }}</p> @enderror
                        @error('motivo') <p class="ins-aviso-error">{{ $message }}</p> @enderror
                @endif

                @foreach ($grupos as $estacion => $personas)
                    <section class="space-y-2" aria-labelledby="est-{{ $loop->index }}">
                        <h3 id="est-{{ $loop->index }}" class="ins-titulo">{{ $estacion }}</h3>
                        <div class="ins-tabla-marco">
                            <table class="ins-tabla">
                                <caption class="sr-only">{{ __('Rol de vacaciones de :estacion', ['estacion' => $estacion]) }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('Persona') }}</th>
                                        <th scope="col" class="!text-right">{{ __('Otorgados') }}</th>
                                        @foreach ([1, 2, 3, 4] as $t) <th scope="col">{{ $t }}{{ __('º trimestre') }}</th> @endforeach
                                        <th scope="col" class="!text-right">{{ __('Solicitados') }}</th>
                                        <th scope="col" class="!text-right">{{ __('Saldo') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($personas as $persona)
                                        @php $solicitados = $persona->diasSolicitados(); @endphp
                                        <tr>
                                            <th scope="row" class="px-3 py-2.5 text-left align-top font-medium text-gray-900 sm:px-4">{{ $persona->nombre }}<span class="block text-xs font-normal text-gray-500">{{ $persona->no_empleado }}@if ($persona->puesto) · {{ $persona->puesto }}@endif</span></th>
                                            <td class="num">{{ $persona->dias_otorgados }}</td>
                                            @foreach ($persona->tramos as $tramo)
                                                <td class="align-top">
                                                    @if ($editar && ! isset($pendientes[$tramo->id]))
                                                        <div class="flex flex-col gap-1">
                                                            <input type="date" name="tramos[{{ $tramo->id }}][inicio]" value="{{ $tramo->inicio?->toDateString() }}" class="ins-campo" aria-label="{{ __('Inicio del :t trimestre de :nombre', ['t' => $tramo->trimestre, 'nombre' => $persona->nombre]) }}">
                                                            <input type="date" name="tramos[{{ $tramo->id }}][fin]" value="{{ $tramo->fin?->toDateString() }}" class="ins-campo" aria-label="{{ __('Término del :t trimestre de :nombre', ['t' => $tramo->trimestre, 'nombre' => $persona->nombre]) }}">
                                                        </div>
                                                    @elseif ($tramo->vacio())
                                                        <span class="text-gray-400">—</span>
                                                    @else
                                                        <span class="whitespace-nowrap">{{ $fmt($tramo->inicio) }} al {{ $fmt($tramo->fin) }}</span>
                                                        <span class="block text-xs text-gray-500">{{ trans_choice(':n día|:n días', $tramo->dias, ['n' => $tramo->dias]) }}</span>
                                                    @endif
                                                    @if (isset($pendientes[$tramo->id]))
                                                        <span class="ins-chip-info">{{ __('Cambio pendiente') }}</span>
                                                    @elseif ($tramo->origen === 'ajustado')
                                                        <span class="ins-chip-neutro">{{ __('Ajustado') }}</span>
                                                        <span class="block text-xs text-gray-600">{{ __('Oficial: :r', ['r' => $tramo->inicio_oficial ? $fmt($tramo->inicio_oficial).' al '.$fmt($tramo->fin_oficial) : __('sin fechas')]) }}</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td class="num">{{ $solicitados }}</td>
                                            <td class="num {{ $solicitados > $persona->dias_otorgados ? 'text-red-700 font-semibold' : '' }}">{{ $persona->dias_otorgados - $solicitados }}@if ($solicitados > $persona->dias_otorgados)<span class="block text-xs">{{ __('excedido') }}</span>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endforeach

                @if ($editar)
                        @unless ($esZona)
                            <div class="ins-card ins-card-pad space-y-2">
                                <label for="motivo" class="ins-etiqueta">{{ __('Motivo del cambio (obligatorio)') }}</label>
                                <textarea id="motivo" name="motivo" rows="2" maxlength="1000" required class="ins-campo">{{ old('motivo') }}</textarea>
                                <p class="text-xs text-gray-500">{{ __('Los cambios no se aplican hasta que la Zona los autorice. Los días se cuentan de lunes a viernes.') }}</p>
                            </div>
                        @endunless
                        <button type="submit" class="ins-btn-primario">{{ $esZona ? __('Guardar cambios') : __('Enviar a autorización') }}</button>
                    </form>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
