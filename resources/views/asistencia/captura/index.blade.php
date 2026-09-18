@php
    /** @var \App\Models\Estacion $estacion */
    /** @var \Carbon\CarbonImmutable $fecha */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Empleado> $roster */
    /** @var list<\App\Enums\EstatusAsistencia> $estatuses */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Estacion> $estaciones */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\ComisionadoVisitante> $comisionadosVisitantes */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\ComisionadoFuera> $comisionadosFuera */
    /** @var bool $puedeGestionarComisionados */
    $esAdministrador = auth()->user()->hasRole('Administrador');
    $queryComisionados = array_filter(['estacion' => $estacion->id, 'fecha' => $fecha->toDateString()]);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Control de Asistencia Diaria') }} — {{ $estacion->nombre }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md border border-brand-green/20 bg-brand-mist px-4 py-3 text-sm text-brand-green-dark">
                    {{ session('status') }}
                </div>
            @endif

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6 space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-gray-600">
                        {{ __('Fecha') }}: <span class="font-medium text-brand-green">{{ $fecha->translatedFormat('d \d\e F \d\e Y') }}</span>
                    </p>

                    @if ($esAdministrador && $estaciones->isNotEmpty())
                        <div class="flex flex-wrap gap-2">
                            @foreach ($estaciones as $opcion)
                                <a href="{{ route('asistencia.captura.index', ['estacion' => $opcion->id, 'fecha' => $fecha->toDateString()]) }}"
                                   class="inline-flex min-h-[36px] items-center rounded-full px-3 py-1 text-xs font-medium transition
                                          {{ $opcion->id === $estacion->id
                                                ? 'bg-brand-green text-white'
                                                : 'bg-brand-mist text-brand-green hover:bg-brand-teal/20' }}">
                                    {{ $opcion->nombre }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($esAdministrador)
                    {{-- Separate GET form: changing the date here RELOADS the
                         page so the roster below actually reflects that
                         date's stored data before anything is edited/saved
                         — it never shares the save form, so the date you're
                         looking at is always the date a save would affect. --}}
                    <form method="GET" action="{{ route('asistencia.captura.index') }}" class="flex flex-wrap items-end gap-2 border-t border-brand-green/10 pt-4">
                        <input type="hidden" name="estacion" value="{{ $estacion->id }}">
                        <div class="max-w-xs">
                            <x-input-label for="fecha-nav" :value="__('Ver / corregir otra fecha')" />
                            <x-text-input id="fecha-nav" name="fecha" type="date" class="block mt-1 w-full"
                                          value="{{ $fecha->toDateString() }}" />
                        </div>
                        <x-secondary-button type="submit">{{ __('Ver esta fecha') }}</x-secondary-button>
                    </form>
                @endif
            </div>

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="POST"
                      action="{{ route('asistencia.captura.update', $esAdministrador ? ['estacion' => $estacion->id] : []) }}"
                      class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="max-w-xs">
                        <x-input-label for="fecha" :value="__('Fecha a capturar')" />

                        {{-- Always the date actually loaded above (never
                             independently editable here) — saving must
                             affect exactly the date shown, never a
                             different one typed in blind. To capture/correct
                             a different date, use "Ver / corregir otra
                             fecha" above first, which reloads the roster for
                             that date, then save from here. --}}
                        <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">
                        <p id="fecha" class="mt-1 text-sm text-gray-700">{{ $fecha->toDateString() }}</p>
                        <x-input-error :messages="$errors->get('fecha')" class="mt-2" />
                    </div>

                    @if ($roster->isEmpty())
                        <p class="text-sm text-gray-500">{{ __('Esta estación no tiene personal activo registrado.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead>
                                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        <th class="py-2 pr-4">{{ __('Nombre') }}</th>
                                        <th class="py-2 pr-4">{{ __('Puesto') }}</th>
                                        <th class="py-2 pr-4">{{ __('Estatus') }}</th>
                                        <th class="py-2 pr-4">{{ __('Fecha inicio') }}</th>
                                        <th class="py-2 pr-4">{{ __('Fecha fin') }}</th>
                                        <th class="py-2 pr-4">{{ __('Notas') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($roster as $empleado)
                                        @php
                                            $registroHoy = $empleado->registrosDiarios->first();
                                            // No default to "Presente" — a person with no
                                            // captured row yet starts blank, forcing the
                                            // estación to explicitly pick a status for
                                            // everyone before saving (never a silent
                                            // assumed-present).
                                            $estatusActual = old("registros.$empleado->id.estatus", $registroHoy?->estatus?->value ?? '');
                                        @endphp
                                        <tr class="align-top">
                                            <td class="py-2 pr-4 font-medium text-gray-800">
                                                {{ $empleado->nombre_completo }}
                                                <input type="hidden" name="registros[{{ $empleado->id }}][empleado_id]" value="{{ $empleado->id }}">
                                            </td>
                                            <td class="py-2 pr-4 text-gray-600">{{ $empleado->puesto }}</td>
                                            <td class="py-2 pr-4">
                                                <select name="registros[{{ $empleado->id }}][estatus]"
                                                        class="block w-full min-h-[44px] rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                                                    <option value="" @selected($estatusActual === '')>{{ __('-- Selecciona --') }}</option>
                                                    @foreach ($estatuses as $estatus)
                                                        <option value="{{ $estatus->value }}" @selected($estatusActual === $estatus->value)>
                                                            {{ $estatus->label() }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$errors->get('registros.'.$empleado->id.'.estatus')" class="mt-1" />
                                            </td>
                                            <td class="py-2 pr-4">
                                                <x-text-input type="date" class="block w-full text-sm"
                                                              name="registros[{{ $empleado->id }}][fecha_inicio]"
                                                              :value="old('registros.'.$empleado->id.'.fecha_inicio', optional($registroHoy?->fecha_inicio)->toDateString())" />
                                                <x-input-error :messages="$errors->get('registros.'.$empleado->id.'.fecha_inicio')" class="mt-1" />
                                            </td>
                                            <td class="py-2 pr-4">
                                                <x-text-input type="date" class="block w-full text-sm"
                                                              name="registros[{{ $empleado->id }}][fecha_fin]"
                                                              :value="old('registros.'.$empleado->id.'.fecha_fin', optional($registroHoy?->fecha_fin)->toDateString())" />
                                                <x-input-error :messages="$errors->get('registros.'.$empleado->id.'.fecha_fin')" class="mt-1" />
                                            </td>
                                            <td class="py-2 pr-4">
                                                <input type="text" name="registros[{{ $empleado->id }}][notas]"
                                                       class="block w-full min-h-[44px] rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal"
                                                       value="{{ old('registros.'.$empleado->id.'.notas', $registroHoy?->notas) }}">
                                                <x-input-error :messages="$errors->get('registros.'.$empleado->id.'.notas')" class="mt-1" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="flex justify-end border-t pt-4">
                            <x-primary-button class="w-full sm:w-auto">{{ __('Guardar asistencia') }}</x-primary-button>
                        </div>
                    @endif
                </form>
            </div>

            @include('asistencia.captura._oficio')

            {{-- Etapa 2 — comisionados_visitantes: personal de otras
                 coordinaciones presentes hoy (ver el plan aprobado). --}}
            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6 space-y-4">
                <h3 class="font-heading text-base font-semibold text-brand-green">
                    {{ __('Personal comisionado de otras coordinaciones presentes hoy') }}
                </h3>

                @if ($comisionadosVisitantes->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('Sin registros para esta fecha.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($comisionadosVisitantes as $visitante)
                            <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="font-medium text-gray-800">{{ $visitante->nombre }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $visitante->direccion_origen }}
                                        @if ($visitante->motivo) &mdash; {{ $visitante->motivo }} @endif
                                        @if ($visitante->fecha_inicio && $visitante->fecha_fin)
                                            ({{ $visitante->fecha_inicio->toDateString() }} &ndash; {{ $visitante->fecha_fin->toDateString() }})
                                        @endif
                                    </p>
                                </div>
                                @can('delete', $visitante)
                                    <form method="POST"
                                          action="{{ route('asistencia.captura.visitantes.destroy', $visitante) }}"
                                          onsubmit="return confirm('{{ __('¿Eliminar este registro?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="estacion" value="{{ $queryComisionados['estacion'] ?? '' }}">
                                        <input type="hidden" name="fecha" value="{{ $queryComisionados['fecha'] ?? '' }}">
                                        <x-danger-button type="submit" class="text-[11px]">{{ __('Eliminar') }}</x-danger-button>
                                    </form>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($puedeGestionarComisionados)
                    <form method="POST" action="{{ route('asistencia.captura.visitantes.store') }}" class="grid grid-cols-1 gap-3 border-t border-brand-green/10 pt-4 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="estacion" value="{{ $estacion->id }}">
                        <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">

                        <div>
                            <x-input-label for="visitante-nombre" :value="__('Nombre')" />
                            <x-text-input id="visitante-nombre" name="nombre" class="block mt-1 w-full" :value="old('nombre')" />
                            <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="visitante-no-trabajador" :value="__('No. de trabajador')" />
                            <x-text-input id="visitante-no-trabajador" name="no_trabajador" class="block mt-1 w-full" :value="old('no_trabajador')" />
                            <x-input-error :messages="$errors->get('no_trabajador')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="visitante-direccion-origen" :value="__('Coordinación / departamento de origen')" />
                            <x-text-input id="visitante-direccion-origen" name="direccion_origen" class="block mt-1 w-full" :value="old('direccion_origen')" />
                            <x-input-error :messages="$errors->get('direccion_origen')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="visitante-motivo" :value="__('Motivo')" />
                            <x-text-input id="visitante-motivo" name="motivo" class="block mt-1 w-full" :value="old('motivo')" />
                            <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="visitante-fecha-inicio" :value="__('Fecha inicio')" />
                            <x-text-input id="visitante-fecha-inicio" type="date" name="fecha_inicio" class="block mt-1 w-full" :value="old('fecha_inicio')" />
                            <x-input-error :messages="$errors->get('fecha_inicio')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="visitante-fecha-fin" :value="__('Fecha fin')" />
                            <x-text-input id="visitante-fecha-fin" type="date" name="fecha_fin" class="block mt-1 w-full" :value="old('fecha_fin')" />
                            <x-input-error :messages="$errors->get('fecha_fin')" class="mt-1" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="visitante-notas" :value="__('Notas')" />
                            <x-text-input id="visitante-notas" name="notas" class="block mt-1 w-full" :value="old('notas')" />
                            <x-input-error :messages="$errors->get('notas')" class="mt-1" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-primary-button type="submit">{{ __('Agregar visitante') }}</x-primary-button>
                        </div>
                    </form>
                @endif
            </div>

            {{-- Etapa 2 — comisionados_fuera: personal propio comisionado
                 fuera hoy (ver el plan aprobado). --}}
            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6 space-y-4">
                <h3 class="font-heading text-base font-semibold text-brand-green">
                    {{ __('Personal de Zona Oriente comisionado fuera hoy') }}
                </h3>

                @if ($comisionadosFuera->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('Sin registros para esta fecha.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($comisionadosFuera as $comisionado)
                            <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="font-medium text-gray-800">{{ $comisionado->empleado?->nombre_completo }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $comisionado->coordinacion_destino }}
                                        @if ($comisionado->ubicacion_destino) &mdash; {{ $comisionado->ubicacion_destino }} @endif
                                        @if ($comisionado->motivo) &mdash; {{ $comisionado->motivo }} @endif
                                        @if ($comisionado->fecha_inicio && $comisionado->fecha_fin)
                                            ({{ $comisionado->fecha_inicio->toDateString() }} &ndash; {{ $comisionado->fecha_fin->toDateString() }})
                                        @endif
                                    </p>
                                </div>
                                @can('delete', $comisionado)
                                    <form method="POST"
                                          action="{{ route('asistencia.captura.comisionados.destroy', $comisionado) }}"
                                          onsubmit="return confirm('{{ __('¿Eliminar este registro?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="estacion" value="{{ $queryComisionados['estacion'] ?? '' }}">
                                        <input type="hidden" name="fecha" value="{{ $queryComisionados['fecha'] ?? '' }}">
                                        <x-danger-button type="submit" class="text-[11px]">{{ __('Eliminar') }}</x-danger-button>
                                    </form>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($puedeGestionarComisionados)
                    <form method="POST" action="{{ route('asistencia.captura.comisionados.store') }}" class="grid grid-cols-1 gap-3 border-t border-brand-green/10 pt-4 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="estacion" value="{{ $estacion->id }}">
                        <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">

                        <div>
                            <x-input-label for="comisionado-empleado" :value="__('Empleado')" />
                            <select id="comisionado-empleado" name="empleado_id"
                                    class="block w-full min-h-[44px] rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                                <option value="">{{ __('Selecciona un empleado') }}</option>
                                @foreach ($roster as $empleado)
                                    <option value="{{ $empleado->id }}" @selected(old('empleado_id') == $empleado->id)>
                                        {{ $empleado->nombre_completo }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('empleado_id')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="comisionado-coordinacion-destino" :value="__('Coordinación destino')" />
                            <x-text-input id="comisionado-coordinacion-destino" name="coordinacion_destino" class="block mt-1 w-full" :value="old('coordinacion_destino')" />
                            <x-input-error :messages="$errors->get('coordinacion_destino')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="comisionado-ubicacion-destino" :value="__('Ubicación destino')" />
                            <x-text-input id="comisionado-ubicacion-destino" name="ubicacion_destino" class="block mt-1 w-full" :value="old('ubicacion_destino')" />
                            <x-input-error :messages="$errors->get('ubicacion_destino')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="comisionado-motivo" :value="__('Motivo')" />
                            <x-text-input id="comisionado-motivo" name="motivo" class="block mt-1 w-full" :value="old('motivo')" />
                            <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="comisionado-fecha-inicio" :value="__('Fecha inicio')" />
                            <x-text-input id="comisionado-fecha-inicio" type="date" name="fecha_inicio" class="block mt-1 w-full" :value="old('fecha_inicio')" />
                            <x-input-error :messages="$errors->get('fecha_inicio')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="comisionado-fecha-fin" :value="__('Fecha fin')" />
                            <x-text-input id="comisionado-fecha-fin" type="date" name="fecha_fin" class="block mt-1 w-full" :value="old('fecha_fin')" />
                            <x-input-error :messages="$errors->get('fecha_fin')" class="mt-1" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="comisionado-notas" :value="__('Notas')" />
                            <x-text-input id="comisionado-notas" name="notas" class="block mt-1 w-full" :value="old('notas')" />
                            <x-input-error :messages="$errors->get('notas')" class="mt-1" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-primary-button type="submit">{{ __('Agregar comisión') }}</x-primary-button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
