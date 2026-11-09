@php
    /** @var \App\Models\Estacion $estacion */
    /** @var \Carbon\CarbonImmutable $fecha */
    /** @var array<string, mixed> $estado */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Estacion> $estaciones */
    /** @var bool $puedeGestionarComisionados */
    $esAdministrador = auth()->user()->can('captureAnyEstacion', \App\Models\RegistroDiario::class);
    $erroresDelOficio = ['oficio', 'numero_oficio', 'firmante_cargo', 'firmante_nombre', 'iniciales', 'firma'];

    $estadoPantalla = array_merge($estado, [
        'pasoInicial' => session('paso') ?? ($errors->hasAny($erroresDelOficio) ? 'oficio' : 'asistencia'),
        'haySinGuardar' => old('registros') !== null,
        'erroresRegistros' => collect($errors->getMessages())
            ->filter(fn ($mensajes, $campo) => str_starts_with($campo, 'registros.'))
            ->map(fn ($mensajes) => $mensajes[0])
            ->all(),
        'puedeGestionar' => $puedeGestionarComisionados,
        'hayDocumento' => $oficio['documento'] !== null,
        'oficioDesactualizado' => $oficio['desactualizado'],
        'rutas' => [
            'fueraCrear' => route('asistencia.captura.comisionados.store'),
            'fuera' => route('asistencia.captura.comisionados.update', '__ID__'),
            'visitanteCrear' => route('asistencia.captura.visitantes.store'),
            'visitante' => route('asistencia.captura.visitantes.update', '__ID__'),
        ],
        'oficio' => [
            'numero' => old('numero_oficio', $estado['oficio']['numero']),
            'cargo' => old('firmante_cargo', $estado['oficio']['cargo']),
            'nombre' => old('firmante_nombre', $estado['oficio']['nombre']),
            'iniciales' => old('iniciales', ''),
        ],
    ]);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Control de Asistencia Diaria') }} — {{ $estacion->nombre }}
        </h2>
    </x-slot>

    <div x-data="capturaAsistencia(@js($estadoPantalla))" x-init="iniciar()" @beforeunload.window="alSalir($event)" class="py-6">
        <div class="mx-auto max-w-[1760px] space-y-4 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="rounded-md border border-brand-green/20 bg-brand-mist px-4 py-3 text-sm text-brand-green-dark" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Barra de trabajo: qué estación y día, cuánto falta y guardar. --}}
            <div class="rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap items-end gap-3">
                        @if ($esAdministrador)
                            {{-- GET aparte: cambiar de estación o día recarga los datos de ese día antes de editar. --}}
                            <form method="GET" action="{{ route('asistencia.captura.index') }}" class="flex flex-wrap items-end gap-3">
                                <div>
                                    <x-input-label for="nav-estacion" :value="__('Estación')" />
                                    <select id="nav-estacion" name="estacion" onchange="this.form.submit()"
                                            class="mt-1 block min-h-[44px] rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal">
                                        @foreach ($estaciones as $opcion)
                                            <option value="{{ $opcion->id }}" @selected($opcion->id === $estacion->id)>{{ $opcion->nombre }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <x-input-label for="nav-fecha" :value="__('Fecha')" />
                                    <x-text-input id="nav-fecha" name="fecha" type="date" class="mt-1 block min-h-[44px] text-sm"
                                                  value="{{ $fecha->toDateString() }}" onchange="this.form.submit()" />
                                </div>
                            </form>
                        @else
                            <p class="text-sm text-gray-600">
                                {{ __('Fecha') }}: <span class="font-medium text-brand-green">{{ $fecha->translatedFormat('d \d\e F \d\e Y') }}</span>
                            </p>
                        @endif
                    </div>

                    <div class="flex flex-1 items-center gap-4 lg:max-w-xl">
                        <div class="min-w-0 flex-1">
                            <div class="flex justify-between text-xs text-gray-600">
                                <span x-text="`${capturados} de ${personas.length} con estatus`"></span>
                                <span x-show="sucio" x-cloak class="font-medium text-amber-700">{{ __('Cambios sin guardar') }}</span>
                                <span x-show="!sucio && pendientes === 0" x-cloak class="font-medium text-brand-green">{{ __('Todo guardado') }}</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="avance">
                                <div class="h-full rounded-full bg-brand-green transition-all" :style="`width: ${avance}%`"></div>
                            </div>
                        </div>
                        <x-primary-button form="form-asistencia" x-bind:disabled="enviandoAsistencia" class="shrink-0 disabled:opacity-60">
                            {{ __('Guardar asistencia') }}
                        </x-primary-button>
                    </div>
                </div>

                <nav class="mt-4 flex gap-1 overflow-x-auto border-t border-brand-green/10 pt-3" aria-label="{{ __('Pasos de la captura') }}">
                    @foreach ([
                        ['asistencia', '1', __('Asistencia'), 'pendientes', ''],
                        ['comisionados', '2', __('Comisionados'), 'totalComisionados', ''],
                        ['oficio', '3', __('Firma y oficio'), '0', ''],
                        ['vista', '', __('Vista previa'), '0', 'xl:hidden'],
                    ] as [$clave, $numero, $titulo, $contador, $clases])
                        <button type="button" @click="paso = '{{ $clave }}'" :aria-current="paso === '{{ $clave }}' ? 'step' : null"
                                class="{{ $clases }} inline-flex min-h-[44px] shrink-0 items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition"
                                :class="paso === '{{ $clave }}' ? 'bg-brand-green text-white' : 'text-gray-600 hover:bg-brand-mist'">
                            @if ($numero !== '')
                                <span class="text-xs opacity-70">{{ $numero }}</span>
                            @endif
                            {{ $titulo }}
                            <span x-show="{{ $contador }} > 0" x-cloak x-text="{{ $contador }}"
                                  class="rounded-full px-1.5 text-xs font-semibold"
                                  :class="paso === '{{ $clave }}' ? 'bg-white/20' : 'bg-amber-100 text-amber-800'"></span>
                        </button>
                    @endforeach
                </nav>
            </div>

            <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,44%)]">
                <div class="min-w-0" :class="paso === 'vista' && 'hidden xl:block'">
                    <div x-show="paso === 'asistencia' || paso === 'vista'">
                        @include('asistencia.captura._paso-asistencia')
                    </div>
                    <div x-show="paso === 'comisionados'" x-cloak>
                        @include('asistencia.captura._paso-comisionados')
                    </div>
                    <div x-show="paso === 'oficio'" x-cloak>
                        @include('asistencia.captura._oficio')
                    </div>
                </div>

                <aside class="min-w-0 xl:sticky xl:top-4" :class="paso === 'vista' ? 'block' : 'hidden xl:block'">
                    @include('asistencia.captura._vista-previa')
                </aside>
            </div>
        </div>

        <div x-show="aviso !== ''" x-cloak x-transition role="status" aria-live="polite"
             class="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-md bg-gray-900 px-4 py-2 text-sm text-white shadow-lg"
             x-text="aviso"></div>
    </div>
</x-app-layout>
