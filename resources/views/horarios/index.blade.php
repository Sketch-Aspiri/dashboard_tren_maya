@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\HorarioPeriodo> $periodos */
    /** @var \App\Models\HorarioPeriodo|null $periodo */
    /** @var \Carbon\CarbonImmutable|null $inicio */
    /** @var list<\Carbon\CarbonImmutable> $dias */
    /** @var array<string, array<string, array{nombre: string, puesto: string|null, no_empleado: string, estacion_id: int|null, celdas: array<string, \App\Models\HorarioTurno>}>> $grupos */
    /** @var \Illuminate\Support\Collection<int, \App\Models\HorarioSolicitud> $pendientes */
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $base = $periodo ? ['anio' => $periodo->anio, 'mes' => $periodo->mes] : [];
    $filtros = array_filter(['estacion' => request('estacion'), 'q' => request('q'), 'editar' => $editar ? 1 : null]);
    $primerDia = $periodo ? \Carbon\CarbonImmutable::create($periodo->anio, $periodo->mes, 1) : null;
    $limites = $primerDia ? [$primerDia->toDateString(), $primerDia->endOfMonth()->toDateString()] : [null, null];
    $esBorrador = $periodo?->estado === \App\Models\HorarioPeriodo::BORRADOR;
    $personasVisibles = collect($grupos)->flatten(1);
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-horarios.titulo :titulo="$periodo ? $meses[$periodo->mes].' '.$periodo->anio : __('Horario')"
                           :detalle="$esZona ? __('Todas las estaciones') : auth()->user()->estacion?->nombre" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6"
             x-data="{
                abierto: false, t: null, tipo: 'cambio_fecha',
                abrir(t) { this.t = t; this.tipo = 'cambio_fecha'; this.abierto = true; },
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
                        <p class="mt-1">{{ __('Crea el horario aquí mismo o sube el Excel oficial para que cada estación reciba el suyo.') }}
                            <a href="{{ route('horarios.crear.create') }}" class="font-semibold underline">{{ __('Crear horario') }}</a> ·
                            <a href="{{ route('horarios.importar.create') }}" class="font-semibold underline">{{ __('Subir horario oficial') }}</a></p>
                    @else
                        <p class="mt-1">{{ __('Edificio publicará el horario y te llegará un aviso.') }}</p>
                    @endif
                </div>
            @else
                @if ($esBorrador)
                    <div class="ins-aviso-alerta" role="status">
                        <span aria-hidden="true" class="mt-0.5 font-bold">!</span>
                        <div class="flex flex-1 flex-wrap items-center justify-between gap-3">
                            <p><span class="font-semibold">{{ __('Este horario es un borrador.') }}</span> {{ __('Las estaciones todavía no lo ven. Edítalo y publícalo para avisarles.') }}</p>
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('horarios.periodos.publicar', $periodo) }}">@csrf<button type="submit" class="ins-btn-primario !min-h-[36px]">{{ __('Publicar y avisar') }}</button></form>
                                <form method="POST" action="{{ route('horarios.periodos.descartar', $periodo) }}">@csrf @method('DELETE')<button type="submit" class="ins-btn-peligro !min-h-[36px]">{{ __('Descartar') }}</button></form>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($periodos as $p)
                        <a href="{{ route('horarios.index', ['anio' => $p->anio, 'mes' => $p->mes] + array_diff_key($filtros, ['editar' => 1])) }}"
                           @if ($p->is($periodo)) aria-current="page" @endif
                           class="{{ $p->is($periodo) ? 'ins-chip-marca' : 'ins-chip-neutro' }} hover:underline">{{ $meses[$p->mes] }} {{ $p->anio }}@if ($p->estado === 'borrador') · {{ __('borrador') }}@endif</a>
                    @endforeach
                    <span class="ml-auto text-xs text-gray-500">
                        @if ($periodo->publicado_at){{ __('Publicado') }}: {{ $periodo->publicado_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}@endif
                        @if ($periodo->version > 1) · {{ __('versión') }} {{ $periodo->version }}@endif
                    </span>
                </div>

                <form method="GET" action="{{ route('horarios.index') }}" class="ins-card ins-card-pad flex flex-wrap items-end gap-3">
                    <input type="hidden" name="anio" value="{{ $periodo->anio }}">
                    <input type="hidden" name="mes" value="{{ $periodo->mes }}">
                    <input type="hidden" name="desde" value="{{ $inicio?->toDateString() }}">
                    @if ($editar)<input type="hidden" name="editar" value="1">@endif
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

                @if ($puedeEditar)
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($editar)
                            <a href="{{ route('horarios.index', $base + ['desde' => $inicio->toDateString()] + array_diff_key($filtros, ['editar' => 1])) }}" class="ins-btn-neutro">{{ __('Terminar edición') }}</a>
                            @unless ($esZona)
                                <p class="text-sm text-gray-600">{{ __('Cambia las celdas que necesites y envíalas a Edificio: el horario no cambia hasta que las autorice.') }}</p>
                            @endunless
                        @else
                            <a href="{{ route('horarios.index', $base + ['desde' => $inicio->toDateString(), 'editar' => 1] + array_diff_key($filtros, ['editar' => 1])) }}" class="ins-btn-primario">{{ __('Editar horario') }}</a>
                        @endif
                    </div>
                @endif

                @if ($esZona && $editar)
                    <div class="grid gap-3 lg:grid-cols-2">
                        {{-- Rellenar por patrón: la misma jornada para una persona en ciertos días de la semana. --}}
                        <details class="ins-card ins-card-pad">
                            <summary class="cursor-pointer text-sm font-semibold text-brand-green">{{ __('Rellenar por patrón') }}</summary>
                            <form method="POST" action="{{ route('horarios.periodos.rellenar', $periodo) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                @csrf
                                <div class="sm:col-span-2">
                                    <label for="r-persona" class="ins-etiqueta">{{ __('Persona') }}</label>
                                    <select id="r-persona" name="no_empleado" required class="ins-campo mt-1">
                                        @foreach ($personasVisibles as $p)
                                            <option value="{{ $p['no_empleado'] }}">{{ $p['nombre'] }} ({{ $p['no_empleado'] }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div><label for="r-desde" class="ins-etiqueta">{{ __('Desde') }}</label><input id="r-desde" name="desde" type="date" required min="{{ $limites[0] }}" max="{{ $limites[1] }}" value="{{ $limites[0] }}" class="ins-campo mt-1"></div>
                                <div><label for="r-hasta" class="ins-etiqueta">{{ __('Hasta') }}</label><input id="r-hasta" name="hasta" type="date" required min="{{ $limites[0] }}" max="{{ $limites[1] }}" value="{{ $limites[1] }}" class="ins-campo mt-1"></div>
                                <fieldset class="sm:col-span-2">
                                    <legend class="ins-etiqueta">{{ __('Días de la semana') }}</legend>
                                    <div class="mt-1 flex flex-wrap gap-3 text-sm">
                                        @foreach ([1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'] as $n => $d)
                                            <label class="inline-flex items-center gap-1"><input type="checkbox" name="dias[]" value="{{ $n }}"> {{ $d }}</label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <div><label for="r-jornada" class="ins-etiqueta">{{ __('Jornada') }} *</label><input id="r-jornada" name="jornada" type="text" required maxlength="255" list="jornadas-sugeridas" class="ins-campo mt-1" placeholder="08:00 a 16:00"></div>
                                <div><label for="r-comida" class="ins-etiqueta">{{ __('Comida (opcional)') }}</label><input id="r-comida" name="comida" type="text" maxlength="100" class="ins-campo mt-1" placeholder="13:00 a 14:00"></div>
                                <div class="sm:col-span-2"><button type="submit" class="ins-btn-secundario">{{ __('Aplicar') }}</button></div>
                            </form>
                        </details>

                        {{-- Agregar persona: del directorio o escribiendo sus datos. --}}
                        <details class="ins-card ins-card-pad">
                            <summary class="cursor-pointer text-sm font-semibold text-brand-green">{{ __('Agregar persona') }}</summary>
                            <form method="POST" action="{{ route('horarios.periodos.personas.store', $periodo) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                @csrf
                                <div class="sm:col-span-2">
                                    <label for="a-empleado" class="ins-etiqueta">{{ __('Del directorio de personal') }}</label>
                                    <select id="a-empleado" name="empleado_id" class="ins-campo mt-1">
                                        <option value="">{{ __('— escribir los datos a mano —') }}</option>
                                        @foreach ($empleadosDisponibles as $e)
                                            <option value="{{ $e->id }}" data-estacion="{{ $e->estacion_id }}">{{ $e->nombre_completo }} ({{ $e->no_empleado }}) · {{ $e->estacion?->nombre }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="a-estacion" class="ins-etiqueta">{{ __('Estación') }} *</label>
                                    <select id="a-estacion" name="estacion_id" required class="ins-campo mt-1">
                                        @foreach ($estaciones as $e)
                                            <option value="{{ $e->id }}">{{ $e->nombre }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div><label for="a-no" class="ins-etiqueta">{{ __('No. Empleado') }}</label><input id="a-no" name="no_empleado" type="text" maxlength="30" class="ins-campo mt-1"></div>
                                <div><label for="a-nombre" class="ins-etiqueta">{{ __('Nombre completo') }}</label><input id="a-nombre" name="nombre" type="text" maxlength="255" class="ins-campo mt-1"></div>
                                <div class="sm:col-span-2"><label for="a-puesto" class="ins-etiqueta">{{ __('Puesto (opcional)') }}</label><input id="a-puesto" name="puesto" type="text" maxlength="255" class="ins-campo mt-1"></div>
                                <div class="sm:col-span-2"><button type="submit" class="ins-btn-secundario">{{ __('Agregar al horario') }}</button></div>
                            </form>
                        </details>
                    </div>
                @endif

                <datalist id="jornadas-sugeridas">
                    <option value="DESCANSO"></option><option value="VACACIONES"></option><option value="COMISIÓN"></option>
                </datalist>

                @if ($editar)
                    <form method="POST" x-data="editorHorario" @input="contar()" @change="contar()"
                          action="{{ $esZona ? route('horarios.turnos.guardar') : route('horarios.cambios.proponer') }}" class="space-y-3">
                        @csrf
                        @if ($esZona) @method('PUT') @endif
                        <input type="hidden" name="anio" value="{{ $periodo->anio }}">
                        <input type="hidden" name="mes" value="{{ $periodo->mes }}">
                        <input type="hidden" name="desde" value="{{ $inicio->toDateString() }}">
                        <input type="hidden" name="estacion" value="{{ request('estacion') }}">
                        <input type="hidden" name="seguir" value="1">
                @endif

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
                                                @if ($esZona && $editar)
                                                    <span x-data="{ seguro: false }" class="mt-1 block">
                                                        <button type="button" x-show="! seguro" @click="seguro = true" class="text-xs font-semibold text-red-700 underline">{{ __('Quitar del horario') }}</button>
                                                        <span x-show="seguro" x-cloak class="text-xs">
                                                            {{ __('¿Quitar a esta persona de todo el mes?') }}
                                                            <button type="submit" formaction="{{ route('horarios.periodos.personas.quitar', [$periodo, $persona['no_empleado']]) }}" formmethod="POST" formnovalidate class="font-semibold text-red-700 underline">{{ __('Sí, quitar') }}</button> ·
                                                            <button type="button" @click="seguro = false" class="underline">{{ __('No') }}</button>
                                                        </span>
                                                    </span>
                                                @endif
                                            </td>
                                            @foreach ($dias as $dia)
                                                @php
                                                    $turno = $persona['celdas'][$dia->toDateString()] ?? null;
                                                    $pend = $turno ? $pendientes->get($turno->id) : null;
                                                    $etiquetaCelda = $persona['nombre'].' · '.$dia->format('d/m/Y');
                                                @endphp
                                                <td class="{{ $editar ? 'min-w-[11rem]' : 'min-w-[8.5rem]' }} text-xs">
                                                    @if (! $turno)
                                                        <span class="text-gray-400">—</span>
                                                    @elseif ($editar && ! $pend)
                                                        <div x-data="{ j: @js($turno->jornada), c: @js($turno->comida ?? ''), j0: @js($turno->jornada), c0: @js($turno->comida ?? ''), get sucio() { return this.j.trim() !== this.j0 || this.c.trim() !== this.c0; } }" :data-sucio="sucio ? 1 : 0" class="space-y-1">
                                                            <input type="text" x-model="j" maxlength="255" list="jornadas-sugeridas" class="ins-campo !min-h-[32px] !px-2 !py-1 text-xs" :class="sucio ? '!border-amber-400' : ''" aria-label="{{ __('Jornada de :c', ['c' => $etiquetaCelda]) }}" placeholder="{{ __('Jornada') }}">
                                                            <input type="text" x-model="c" maxlength="100" class="ins-campo !min-h-[32px] !px-2 !py-1 text-xs" aria-label="{{ __('Comida de :c', ['c' => $etiquetaCelda]) }}" placeholder="{{ __('Comida') }}">
                                                            <template x-if="sucio">
                                                                <span>
                                                                    <input type="hidden" name="turnos[{{ $turno->id }}][jornada]" :value="j">
                                                                    <input type="hidden" name="turnos[{{ $turno->id }}][comida]" :value="c">
                                                                    <input type="hidden" name="turnos[{{ $turno->id }}][antes]" value="{{ $huella($turno) }}">
                                                                </span>
                                                            </template>
                                                        </div>
                                                    @else
                                                        <span class="block whitespace-pre-line font-medium text-gray-900">{{ $turno->jornada !== '' ? $turno->jornada : '—' }}</span>
                                                        @if ($turno->comida && mb_strtolower($turno->comida) !== mb_strtolower($turno->jornada))
                                                            <span class="block text-gray-500">{{ __('Comida') }}: {{ $turno->comida }}</span>
                                                        @endif
                                                        @if ($turno->ajustado())
                                                            <span class="ins-chip-alerta mt-1" title="{{ __('Oficial') }}: {{ $turno->jornada_oficial }}@if ($turno->editor) · {{ $turno->editor->name }}@endif @if ($turno->editado_at) · {{ $turno->editado_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}@endif">{{ __('Ajustado') }}</span>
                                                        @endif
                                                        @if ($pend)
                                                            <span class="ins-chip-info mt-1">{{ __('Cambio pedido') }}</span>
                                                            <span class="block text-gray-500">
                                                                @if ($pend->tipo === 'cambio_fecha')
                                                                    {{ __('Intercambio con el :f', ['f' => $pend->nueva_fecha?->format('d/m')]) }}
                                                                @else
                                                                    {{ __('Propuesto') }}: {{ $pend->nueva_jornada }}@if ($pend->nueva_comida) · {{ $pend->nueva_comida }}@endif
                                                                @endif
                                                            </span>
                                                        @elseif ($puedeSolicitar && ! $editar)
                                                            <button type="button" class="mt-1 block text-xs font-semibold text-brand-teal underline"
                                                                    @click="abrir(@js([
                                                                        'url' => route('horarios.solicitudes.store', $turno),
                                                                        'nombre' => $persona['nombre'],
                                                                        'fecha' => ucfirst($dia->locale('es')->translatedFormat('l j \d\e F')),
                                                                        'fechaIso' => $dia->toDateString(),
                                                                        'jornada' => $turno->jornada,
                                                                        'comida' => $turno->comida,
                                                                    ]))"
                                                                    aria-label="{{ __('Intercambiar la fecha de :c', ['c' => $etiquetaCelda]) }}">{{ __('Intercambiar fecha') }}</button>
                                                        @endif
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

                @if ($editar)
                        <div class="ins-barra-acciones">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm" :class="n > 0 ? 'text-amber-700' : 'text-gray-500'" role="status"
                                   x-text="n > 0 ? (n === 1 ? '{{ __('1 celda cambiada') }}' : n + ' {{ __('celdas cambiadas') }}') : '{{ __('Sin cambios todavía') }}'"></p>
                                @if ($esZona)
                                    <label for="nota" class="sr-only">{{ __('Nota del cambio') }}</label>
                                    <input id="nota" name="nota" type="text" maxlength="500" class="ins-campo mt-1" placeholder="{{ __('Nota del cambio (opcional)') }}">
                                @else
                                    <label for="motivo" class="sr-only">{{ __('Motivo de los cambios') }}</label>
                                    <textarea id="motivo" name="motivo" rows="2" minlength="10" maxlength="2000" required class="ins-campo mt-1" placeholder="{{ __('Motivo de los cambios (Edificio lo ve al autorizar)') }}"></textarea>
                                @endif
                            </div>
                            <button type="submit" class="ins-btn-primario" :disabled="n === 0">{{ $esZona ? __('Guardar cambios') : __('Enviar a Edificio') }}</button>
                        </div>
                    </form>
                    <script>
                        document.addEventListener('alpine:init', () => {
                            Alpine.data('editorHorario', () => ({
                                n: 0,
                                contar() { this.$nextTick(() => { this.n = this.$root.querySelectorAll('[data-sucio="1"]').length; }); },
                            }));
                        });
                    </script>
                @endif
            @endif

            @if ($puedeSolicitar && ! $editar)
                {{-- Intercambiar la fecha de un turno con otro día de la misma persona (Edificio lo autoriza). --}}
                <div x-show="abierto" x-cloak class="fixed inset-0 z-40 flex items-end justify-center bg-black/40 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="modal-titulo" @click.self="cerrar()">
                    <form method="POST" :action="t?.url" class="ins-card w-full max-w-lg space-y-4 p-5" x-show="abierto">
                        @csrf
                        <input type="hidden" name="tipo" value="cambio_fecha">
                        <div>
                            <h3 id="modal-titulo" class="ins-titulo">{{ __('Intercambiar fecha') }}</h3>
                            <p class="mt-1 text-sm text-gray-600"><span class="font-medium text-gray-900" x-text="t?.nombre"></span> · <span x-text="t?.fecha"></span></p>
                            <p class="text-xs text-gray-500">{{ __('Ahora') }}: <span x-text="t?.jornada || '—'"></span></p>
                        </div>

                        <div>
                            <label for="m-fecha" class="ins-etiqueta">{{ __('Fecha con la que se intercambia') }} *</label>
                            <input id="m-fecha" name="nueva_fecha" type="date" min="{{ $limites[0] }}" max="{{ $limites[1] }}" class="ins-campo mt-1" required>
                            <p class="mt-1 text-xs text-gray-500">{{ __('La persona hará en esa fecha lo que hoy tiene en este día, y viceversa. Para cambiar solo la jornada de un día, usa «Editar horario».') }}</p>
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
