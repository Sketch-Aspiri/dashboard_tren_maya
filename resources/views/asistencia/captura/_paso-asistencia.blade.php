{{-- Paso 1. Cada persona elige su estatus; los campos extra solo aparecen si ese estatus los usa. --}}
<div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-5">
    <template x-if="personas.length === 0">
        <p class="text-sm text-gray-500">{{ __('Esta estación no tiene personal activo registrado.') }}</p>
    </template>

    <form id="form-asistencia" method="POST" @submit="alGuardar($event)"
          action="{{ route('asistencia.captura.update', $esAdministrador ? ['estacion' => $estacion->id] : []) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">
        <x-input-error :messages="$errors->get('fecha')" class="mb-2" />

        <div x-show="personas.length > 0" class="flex flex-wrap items-center gap-2 pb-3">
            <label class="sr-only" for="buscar-persona">{{ __('Buscar persona') }}</label>
            <input id="buscar-persona" type="search" x-model="busqueda" placeholder="{{ __('Buscar por nombre, puesto o número') }}"
                   @keydown.enter.prevent
                   class="min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal sm:w-64">
            <label class="inline-flex min-h-[44px] items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" x-model="soloPendientes" class="rounded border-gray-300 text-brand-green focus:ring-brand-teal">
                {{ __('Solo pendientes') }}
            </label>
            <div class="flex flex-wrap gap-2 sm:ml-auto">
                <button type="button" x-show="continuidadesPendientes.length > 0" x-cloak @click="continuarTodos()"
                        class="min-h-[44px] rounded-md border border-brand-teal/40 px-3 text-sm font-medium text-brand-green hover:bg-brand-mist"
                        x-text="`Continuar periodos vigentes (${continuidadesPendientes.length})`"></button>
                <button type="button" x-show="pendientes > 0" x-cloak @click="marcarPendientesPresentes()"
                        class="min-h-[44px] rounded-md border border-brand-green/30 px-3 text-sm font-medium text-brand-green hover:bg-brand-mist"
                        x-text="`Marcar pendientes como presentes (${pendientes})`"></button>
            </div>
        </div>

        <ul class="divide-y divide-gray-100 border-t border-gray-100">
            <template x-for="p in personas" :key="p.id">
                <li :id="`persona-${p.id}`" x-show="visible(p)" class="border-l-2 py-3 pl-3 transition-colors"
                    :class="p.estatus ? 'border-transparent' : 'border-amber-400'">
                    <input type="hidden" :name="`registros[${p.id}][empleado_id]`" :value="p.id">

                    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900" x-text="p.nombre"></p>
                            <p class="truncate text-xs text-gray-500" x-text="p.puesto"></p>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5 lg:shrink-0">
                            <template x-for="r in rapidos" :key="r.valor">
                                <button type="button" @click="fijarEstatus(p, r.valor)" x-text="r.texto" :aria-pressed="p.estatus === r.valor"
                                        class="min-h-[40px] rounded-md border px-2.5 text-xs font-medium transition"
                                        :class="p.estatus === r.valor ? 'border-brand-green bg-brand-green text-white' : 'border-gray-300 text-gray-700 hover:bg-brand-mist'"></button>
                            </template>
                            <select :name="`registros[${p.id}][estatus]`" :aria-label="`Estatus de ${p.nombre}`" x-model="p.estatus" @change="fijarEstatus(p, p.estatus)"
                                    class="min-h-[40px] w-44 rounded-md border-gray-300 text-xs shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                                <option value="">{{ __('-- Selecciona --') }}</option>
                                @foreach ($estado['catalogo'] as $opcion)
                                    <option value="{{ $opcion['valor'] }}">{{ $opcion['etiqueta'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <p class="mt-1 text-xs text-red-600" x-show="errorDe(p, 'estatus')" x-text="errorDe(p, 'estatus')" x-cloak></p>

                    {{-- Periodo que venía de un día anterior y sigue vigente: sugerencia, nunca automático. --}}
                    <div x-show="puedeContinuar(p)" x-cloak class="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-brand-mist px-3 py-1.5 text-xs text-brand-green-dark">
                        <span x-text="p.continuidad ? `Sigue en ${p.continuidad.etiqueta} hasta el ${p.continuidad.hasta}` : ''"></span>
                        <button type="button" @click="continuar(p)" class="min-h-[32px] font-semibold underline">{{ __('Continuar') }}</button>
                    </div>

                    {{-- Comisionado a otra área: se imprime en la sección E, no en su lista. --}}
                    <template x-if="comisionDe(p)">
                        <div class="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-amber-50 px-3 py-1.5 text-xs text-amber-900">
                            <span x-text="`Comisionado a ${comisionDe(p).coordinacion_destino || 'otra área'}: sale en la sección ${seccionDe(p)}`"></span>
                            <button type="button" @click="abrirFuera(comisionDe(p))" class="min-h-[32px] font-semibold underline">{{ __('Ver comisión') }}</button>
                        </div>
                    </template>

                    <div x-show="p.estatus === 'comision' && !comisionDe(p) && puedeGestionar" x-cloak class="mt-2 text-xs text-gray-600">
                        {{ __('Seguirá en su lista con estatus COMISIÓN.') }}
                        <button type="button" @click="abrirFuera(null, p.id)" class="min-h-[32px] font-semibold text-brand-green underline">{{ __('¿Salió a otra área? Pásalo a la sección E') }}</button>
                    </div>

                    <div x-show="usaPeriodo(p)" x-cloak class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-[150px_150px_minmax(0,1fr)]">
                        <label class="text-xs text-gray-600">{{ __('Desde') }}
                            <input type="date" :name="`registros[${p.id}][fecha_inicio]`" x-model="p.fecha_inicio" @input="tocar(p)"
                                   class="mt-0.5 block min-h-[40px] w-full rounded-md border-gray-300 text-xs shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                            <span class="text-red-600" x-text="errorDe(p, 'fecha_inicio')"></span>
                        </label>
                        <label class="text-xs text-gray-600">{{ __('Hasta') }}
                            <input type="date" :name="`registros[${p.id}][fecha_fin]`" x-model="p.fecha_fin" @input="tocar(p)" :min="p.fecha_inicio"
                                   class="mt-0.5 block min-h-[40px] w-full rounded-md border-gray-300 text-xs shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                            <span class="text-red-600" x-text="errorDe(p, 'fecha_fin')"></span>
                        </label>
                        <label class="col-span-2 text-xs text-gray-600 sm:col-span-1">{{ __('Detalle (se imprime junto al estatus)') }}
                            <input type="text" maxlength="1000" :name="`registros[${p.id}][notas]`" x-model="p.notas" @input="tocar(p)" :placeholder="pistaDetalle(p)"
                                   class="mt-0.5 block min-h-[40px] w-full rounded-md border-gray-300 text-xs shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                            <span class="text-red-600" x-text="errorDe(p, 'notas')"></span>
                        </label>
                    </div>
                </li>
            </template>
        </ul>
    </form>
</div>
