@php
    /** @var array<string, mixed> $filtros */
    /** @var \Illuminate\Support\Collection<int, \App\Models\PtarHallazgo> $hallazgos */
    /** @var \Illuminate\Support\Collection<int, \App\Models\PtarPlanta> $plantas */
    /** @var bool $puedeGestionar */
    /** @var bool $ticketsDisponible */
    /** @var array<string, string> $equipos */
    $tono = ['abierto' => 'ins-chip-alerta', 'en_proceso' => 'ins-chip-info', 'cerrado' => 'ins-chip-ok'];
    $textoEstado = ['abierto' => __('Abierto'), 'en_proceso' => __('En proceso'), 'cerrado' => __('Cerrado')];
    $prioridad = ['alta' => 'ins-chip-error', 'media' => 'ins-chip-alerta', 'baja' => 'ins-chip-neutro'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-insumos.titulo :titulo="__('Hallazgos de PTAR')" :seccion="__('PTAR')" :grupo="__('Otros')"
                          :detalle="trans_choice(':n hallazgo|:n hallazgos', $hallazgos->count(), ['n' => $hallazgos->count()])"
                          :volver="route('ptar.index')" :volver-texto="__('PTAR del mes')" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-insumos.avisos />
            @error('tickets') <p class="ins-aviso-error">{{ $message }}</p> @enderror

            <form method="GET" action="{{ route('ptar.hallazgos.index') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="f-estado" class="ins-etiqueta">{{ __('Estado') }}</label>
                    <select id="f-estado" name="estado" class="ins-campo mt-1">
                        @foreach (['activos' => __('Sin cerrar'), 'todos' => __('Todos'), 'abierto' => __('Abiertos'), 'en_proceso' => __('En proceso'), 'cerrado' => __('Cerrados')] as $v => $t)
                            <option value="{{ $v }}" @selected(($filtros['estado'] ?? 'activos') === $v)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-planta" class="ins-etiqueta">{{ __('Planta') }}</label>
                    <select id="f-planta" name="planta" class="ins-campo mt-1">
                        <option value="">{{ __('Todas') }}</option>
                        @foreach ($plantas as $planta)
                            <option value="{{ $planta->id }}" @selected((int) ($filtros['planta'] ?? 0) === $planta->id)>{{ $planta->estacion->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-tipo" class="ins-etiqueta">{{ __('Tipo') }}</label>
                    <select id="f-tipo" name="tipo" class="ins-campo mt-1">
                        <option value="">{{ __('Todos') }}</option>
                        <option value="problematica" @selected(($filtros['tipo'] ?? '') === 'problematica')>{{ __('Problemáticas') }}</option>
                        <option value="propuesta" @selected(($filtros['tipo'] ?? '') === 'propuesta')>{{ __('Propuestas') }}</option>
                    </select>
                </div>
                <button type="submit" class="ins-btn-neutro">{{ __('Aplicar filtros') }}</button>
                @if ($puedeGestionar)
                    <a href="{{ route('ptar.oficio.create') }}" class="ins-btn-secundario ml-auto">{{ __('Oficio mensual (Word)') }}</a>
                @endif
            </form>

            @if ($puedeGestionar)
                <details class="ins-card ins-card-pad" @if ($errors->any() && old('planta_id')) open @endif>
                    <summary class="ins-titulo cursor-pointer">{{ __('Registrar un hallazgo') }}</summary>
                    <form method="POST" action="{{ route('ptar.hallazgos.store') }}" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <label for="n-planta" class="ins-etiqueta">{{ __('Planta') }}</label>
                            <select id="n-planta" name="planta_id" required class="ins-campo mt-1">
                                @foreach ($plantas as $planta)
                                    <option value="{{ $planta->id }}" @selected((int) old('planta_id') === $planta->id)>{{ $planta->estacion->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('planta_id')" class="mt-1" />
                        </div>
                        @include('ptar.partials.hallazgo-campos', ['h' => null, 'id' => 'n', 'equipos' => $equipos])
                        <button type="submit" class="ins-btn-primario">{{ __('Guardar hallazgo') }}</button>
                    </form>
                </details>
            @endif

            @forelse ($hallazgos as $h)
                <article class="ins-card ins-card-pad space-y-3" aria-labelledby="h-{{ $h->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 id="h-{{ $h->id }}" class="ins-titulo">{{ $h->planta->estacion->nombre }}</h3>
                        <span class="{{ $tono[$h->estado] }}">{{ $textoEstado[$h->estado] }}</span>
                        <span class="{{ $prioridad[$h->prioridad] ?? 'ins-chip-neutro' }}">{{ __(ucfirst($h->prioridad)) }}</span>
                        <span class="ins-chip-neutro">{{ $h->tipo === 'propuesta' ? __('Propuesta') : __('Problemática') }}</span>
                        @if ($h->equipo)<span class="ins-chip-neutro">{{ $equipos[$h->equipo] ?? $h->equipo }}</span>@endif
                        @if ($h->estaVencido())<span class="ins-chip-error">{{ __('Compromiso vencido') }}</span>@endif
                    </div>
                    <p class="whitespace-pre-line text-sm text-gray-800">{{ $h->descripcion }}</p>
                    @if ($h->propuesta)
                        <p class="whitespace-pre-line text-sm text-gray-600"><span class="font-semibold">{{ __('Propuesta:') }}</span> {{ $h->propuesta }}</p>
                    @endif
                    <dl class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-gray-500">
                        <div><dt class="inline">{{ __('Detectado') }}:</dt> <dd class="inline text-gray-700">{{ $h->fecha_detectado?->format('d/m/Y') ?? '—' }}</dd></div>
                        <div><dt class="inline">{{ __('Compromiso') }}:</dt> <dd class="inline text-gray-700">{{ $h->fecha_compromiso?->format('d/m/Y') ?? '—' }}</dd></div>
                        @if ($h->fecha_cierre)<div><dt class="inline">{{ __('Cierre') }}:</dt> <dd class="inline text-gray-700">{{ $h->fecha_cierre->format('d/m/Y') }}</dd></div>@endif
                        @if ($h->tarjeta)<div><dt class="inline">{{ __('Tarjeta') }}:</dt> <dd class="inline text-gray-700">{{ $h->tarjeta }}</dd></div>@endif
                    </dl>

                    <x-seguimiento-tickets :modelo="$h" />

                    @if ($puedeGestionar)
                        <details>
                            <summary class="ins-enlace cursor-pointer">{{ __('Editar') }}<span class="sr-only">: {{ __('hallazgo :n de :e', ['n' => $loop->iteration, 'e' => $h->planta->estacion->nombre]) }}</span></summary>
                            <form method="POST" action="{{ route('ptar.hallazgos.update', $h) }}" class="mt-3 space-y-3">
                                @csrf
                                @method('PUT')
                                @include('ptar.partials.hallazgo-campos', ['h' => $h, 'id' => 'e'.$h->id, 'equipos' => $equipos])
                                <button type="submit" class="ins-btn-secundario">{{ __('Guardar cambios') }}<span class="sr-only">: {{ __('hallazgo :n de :e', ['n' => $loop->iteration, 'e' => $h->planta->estacion->nombre]) }}</span></button>
                            </form>
                        </details>

                        @if ($h->estaAbierto() && $h->vinculosTickets->isEmpty())
                            @if ($h->tickets_enviado_at && $h->tickets_enviado_at->gt(now()->subHour()))
                                <p class="text-sm text-gray-500">{{ __('Mandado a Tickets el :f; el folio aparecerá en unos momentos.', ['f' => $h->tickets_enviado_at->timezone('America/Cancun')->format('d/m/Y H:i')]) }}</p>
                            @elseif ($ticketsDisponible)
                                <form method="POST" action="{{ route('ptar.hallazgos.tickets', $h) }}">
                                    @csrf
                                    <button type="submit" class="ins-btn-secundario">{{ $h->tickets_enviado_at ? __('Reintentar envío a Tickets (sin folio)') : __('Crear actividad en Tickets') }}<span class="sr-only">: {{ __('hallazgo :n de :e', ['n' => $loop->iteration, 'e' => $h->planta->estacion->nombre]) }}</span></button>
                                </form>
                            @endif
                        @endif
                    @endif
                </article>
            @empty
                <p class="ins-card ins-card-pad text-sm text-gray-500">{{ __('No hay hallazgos con estos filtros.') }}</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
