@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\HorarioPeriodo> $periodos */
    /** @var \App\Models\HorarioPeriodo|null $periodo */
    /** @var \Carbon\CarbonImmutable|null $inicio */
    /** @var list<\Carbon\CarbonImmutable> $dias */
    /** @var array<string, array<string, array{nombre: string, puesto: string|null, no_empleado: string, celdas: array<string, \App\Models\HorarioTurno>}>> $grupos */
    /** @var array<int, int> $pendientes */
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $base = $periodo ? ['anio' => $periodo->anio, 'mes' => $periodo->mes] : [];
    $filtros = array_filter(['estacion' => request('estacion'), 'q' => request('q')]);
    $limites = $periodo ? [\Carbon\CarbonImmutable::create($periodo->anio, $periodo->mes, 1)->toDateString(), \Carbon\CarbonImmutable::create($periodo->anio, $periodo->mes, 1)->endOfMonth()->toDateString()] : [null, null];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-horarios.titulo :titulo="$periodo ? $meses[$periodo->mes].' '.$periodo->anio : __('Horario')"
                           :detalle="$esZona ? __('Todas las estaciones') : auth()->user()->estacion?->nombre" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6"
             x-data="{
                abierto: false, t: null, tipo: 'cambio_turno',
                abrir(t) { this.t = t; this.tipo = 'cambio_turno'; this.abierto = true; this.$nextTick(() => this.$refs.jornada?.focus()); },
                cerrar() { this.abierto = false; },
             }"
             @keydown.escape.window="cerrar()">

            <x-horarios.nav activo="horario" />

            <x-insumos.avisos />

            @if ($esZona && $pendientesZona > 0)
                <div class="ins-aviso-alerta" role="status">
                    <span aria-hidden="true" class="mt-0.5 font-bold">!</span>
                    <p>{{ trans_choice(':n solicitud de cambio espera tu autorización.|:n solicitudes de cambio esperan tu autorización.', $pendientesZona, ['n' => $pendientesZona]) }}
                        <a href="{{ route('horarios.solicitudes.index') }}" class="font-semibold underline">{{ __('Revisar') }}</a></p>
                </div>
            @endif

            @if ($periodo === null)
                <div class="ins-card ins-card-pad text-center text-sm text-gray-600">
                    <p class="font-semibold text-gray-900">{{ __('Todavía no hay un horario publicado.') }}</p>
                    @if ($esZona)
                        <p class="mt-1">{{ __('Sube el horario oficial para que cada estación reciba el suyo.') }}
                            <a href="{{ route('horarios.importar.create') }}" class="font-semibold underline">{{ __('Subir horario oficial') }}</a></p>
                    @else
                        <p class="mt-1">{{ __('Edificio publicará el horario y te llegará un aviso.') }}</p>
                    @endif
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($periodos as $p)
                        <a href="{{ route('horarios.index', ['anio' => $p->anio, 'mes' => $p->mes] + $filtros) }}"
                           @if ($p->is($periodo)) aria-current="page" @endif
                           class="{{ $p->is($periodo) ? 'ins-chip-marca' : 'ins-chip-neutro' }} hover:underline">{{ $meses[$p->mes] }} {{ $p->anio }}</a>
                    @endforeach
                    <span class="ml-auto text-xs text-gray-500">{{ __('Publicado') }}: {{ $periodo->publicado_at?->setTimezone('America/Cancun')->format('d/m/Y H:i') }}@if ($periodo->version > 1) · {{ __('versión') }} {{ $periodo->version }}@endif</span>
                </div>

                <form method="GET" action="{{ route('horarios.index') }}" class="ins-card ins-card-pad flex flex-wrap items-end gap-3">
                    <input type="hidden" name="anio" value="{{ $periodo->anio }}">
                    <input type="hidden" name="mes" value="{{ $periodo->mes }}">
                    <input type="hidden" name="desde" value="{{ $inicio?->toDateString() }}">
                    @if ($esZona)
                        <div>
                            <label for="f-estacion" class="ins-etiqueta">{{ __('Estación') }}</label>
                            <select id="f-estacion" name="estacion" class="ins-campo mt-1 min-w-[12rem]" onchange="this.form.submit()">
                                <option value="">{{ __('Todas') }}</option>
                                @foreach ($estaciones as $e)
                                    <option value="{{ $e->id }}" @selected((int) request('estacion') === $e->id)>{{ $e->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <label for="f-q" class="ins-etiqueta">{{ __('Persona') }}</label>
                        <input id="f-q" name="q" type="search" maxlength="100" value="{{ request('q') }}" placeholder="{{ __('Nombre o No. de empleado') }}" class="ins-campo mt-1 min-w-[14rem]">
                    </div>
                    <button type="submit" class="ins-btn-secundario">{{ __('Buscar') }}</button>

                    <div class="ml-auto flex items-center gap-2">
                        <a href="{{ route('horarios.index', $base + ['desde' => $inicio->subWeek()->toDateString()] + $filtros) }}" class="ins-btn-neutro !min-h-[40px] !px-3" aria-label="{{ __('Semana anterior') }}">←</a>
                        <span class="text-sm font-semibold text-gray-900">{{ $dias[0]->locale('es')->translatedFormat('j M') }} – {{ $dias[6]->locale('es')->translatedFormat('j M Y') }}</span>
                        <a href="{{ route('horarios.index', $base + ['desde' => $inicio->addWeek()->toDateString()] + $filtros) }}" class="ins-btn-neutro !min-h-[40px] !px-3" aria-label="{{ __('Semana siguiente') }}">→</a>
                    </div>
                </form>

                @forelse ($grupos as $estacion => $personas)
                    <section aria-labelledby="est-{{ $loop->index }}" class="space-y-2">
                        <h3 id="est-{{ $loop->index }}" class="ins-titulo">{{ $estacion }} <span class="font-normal text-gray-500">({{ count($personas) }})</span></h3>
                        <div class="ins-tabla-marco">
                            <table class="ins-tabla">
                                <caption class="sr-only">{{ __('Horario semanal de :estacion', ['estacion' => $estacion]) }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('Persona') }}</th>
                                        @foreach ($dias as $dia)
                                            <th scope="col">{{ ucfirst($dia->locale('es')->translatedFormat('D j')) }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($personas as $persona)
                                        <tr>
                                            <td class="min-w-[13rem]">
                                                <span class="fuerte">{{ $persona['nombre'] }}</span>
                                                <span class="block text-xs text-gray-500">{{ $persona['no_empleado'] }}@if ($persona['puesto']) · {{ $persona['puesto'] }}@endif</span>
                                            </td>
                                            @foreach ($dias as $dia)
                                                @php $turno = $persona['celdas'][$dia->toDateString()] ?? null; @endphp
                                                <td class="min-w-[8.5rem] text-xs">
                                                    @if ($turno)
                                                        <span class="block whitespace-pre-line font-medium text-gray-900">{{ $turno->jornada !== '' ? $turno->jornada : '—' }}</span>
                                                        @if ($turno->comida && mb_strtolower($turno->comida) !== mb_strtolower($turno->jornada))
                                                            <span class="block text-gray-500">{{ __('Comida') }}: {{ $turno->comida }}</span>
                                                        @endif
                                                        @if ($turno->ajustado())
                                                            <span class="ins-chip-alerta mt-1" title="{{ __('Oficial') }}: {{ $turno->jornada_oficial }}">{{ __('Ajustado') }}</span>
                                                        @endif
                                                        @if (isset($pendientes[$turno->id]))
                                                            <span class="ins-chip-info mt-1">{{ __('Cambio pedido') }}</span>
                                                        @elseif ($puedeSolicitar)
                                                            <button type="button" class="mt-1 block text-xs font-semibold text-brand-teal underline"
                                                                    @click="abrir(@js([
                                                                        'url' => route('horarios.solicitudes.store', $turno),
                                                                        'nombre' => $persona['nombre'],
                                                                        'fecha' => ucfirst($dia->locale('es')->translatedFormat('l j \d\e F')),
                                                                        'fechaIso' => $dia->toDateString(),
                                                                        'jornada' => $turno->jornada,
                                                                        'comida' => $turno->comida,
                                                                    ]))"
                                                                    aria-label="{{ __('Pedir cambio para :nombre el :fecha', ['nombre' => $persona['nombre'], 'fecha' => $dia->format('d/m/Y')]) }}">{{ __('Pedir cambio') }}</button>
                                                        @endif
                                                    @else
                                                        <span class="text-gray-400">—</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @empty
                    <div class="ins-card ins-card-pad text-center text-sm text-gray-600">{{ __('No hay turnos para esta semana con esos filtros.') }}</div>
                @endforelse
            @endif

            @if ($puedeSolicitar)
                {{-- Pedir un cambio: de turno (jornada nueva) o de fecha (se intercambian los dos días). --}}
                <div x-show="abierto" x-cloak class="fixed inset-0 z-40 flex items-end justify-center bg-black/40 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="modal-titulo" @click.self="cerrar()">
                    <form method="POST" :action="t?.url" class="ins-card w-full max-w-lg space-y-4 p-5" x-show="abierto">
                        @csrf
                        <div>
                            <h3 id="modal-titulo" class="ins-titulo">{{ __('Pedir cambio de horario') }}</h3>
                            <p class="mt-1 text-sm text-gray-600"><span class="font-medium text-gray-900" x-text="t?.nombre"></span> · <span x-text="t?.fecha"></span></p>
                            <p class="text-xs text-gray-500">{{ __('Ahora') }}: <span x-text="t?.jornada || '—'"></span></p>
                        </div>

                        <fieldset class="flex flex-wrap gap-4 text-sm">
                            <legend class="sr-only">{{ __('Tipo de cambio') }}</legend>
                            <label class="inline-flex items-center gap-2"><input type="radio" name="tipo" value="cambio_turno" x-model="tipo"> {{ __('Cambiar la jornada de ese día') }}</label>
                            <label class="inline-flex items-center gap-2"><input type="radio" name="tipo" value="cambio_fecha" x-model="tipo"> {{ __('Intercambiar con otra fecha') }}</label>
                        </fieldset>

                        <div x-show="tipo === 'cambio_turno'" class="grid gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="m-jornada" class="ins-etiqueta">{{ __('Jornada nueva') }} *</label>
                                <input id="m-jornada" x-ref="jornada" name="nueva_jornada" type="text" maxlength="255" list="jornadas-sugeridas" class="ins-campo mt-1"
                                       placeholder="{{ __('Ej. 08:00 a 16:00 o DESCANSO') }}" :required="tipo === 'cambio_turno'" :disabled="tipo !== 'cambio_turno'">
                                <datalist id="jornadas-sugeridas">
                                    <option value="DESCANSO"></option><option value="VACACIONES"></option><option value="COMISIÓN"></option>
                                </datalist>
                            </div>
                            <div class="sm:col-span-2">
                                <label for="m-comida" class="ins-etiqueta">{{ __('Horario de comida (opcional)') }}</label>
                                <input id="m-comida" name="nueva_comida" type="text" maxlength="100" class="ins-campo mt-1" placeholder="{{ __('Ej. 13:00 a 14:00') }}" :disabled="tipo !== 'cambio_turno'">
                            </div>
                        </div>

                        <div x-show="tipo === 'cambio_fecha'">
                            <label for="m-fecha" class="ins-etiqueta">{{ __('Fecha con la que se intercambia') }} *</label>
                            <input id="m-fecha" name="nueva_fecha" type="date" min="{{ $limites[0] }}" max="{{ $limites[1] }}" class="ins-campo mt-1" :required="tipo === 'cambio_fecha'" :disabled="tipo !== 'cambio_fecha'">
                            <p class="mt-1 text-xs text-gray-500">{{ __('La persona hará en esa fecha lo que hoy tiene en este día, y viceversa.') }}</p>
                        </div>

                        <div>
                            <label for="m-motivo" class="ins-etiqueta">{{ __('Motivo (detállalo para que Edificio pueda autorizar)') }} *</label>
                            <textarea id="m-motivo" name="motivo" rows="3" minlength="10" maxlength="2000" required class="ins-campo mt-1"></textarea>
                        </div>

                        <div class="flex justify-end gap-2">
                            <button type="button" class="ins-btn-neutro" @click="cerrar()">{{ __('Cancelar') }}</button>
                            <button type="submit" class="ins-btn-primario">{{ __('Enviar a Edificio') }}</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
