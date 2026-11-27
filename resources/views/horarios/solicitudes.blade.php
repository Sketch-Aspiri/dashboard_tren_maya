@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator<\App\Models\HorarioSolicitud> $solicitudes */
    $etiquetas = ['pendiente' => 'Pendientes', 'autorizada' => 'Autorizadas', 'rechazada' => 'Rechazadas', 'cancelada' => 'Canceladas'];
    $chips = ['pendiente' => 'ins-chip-info', 'autorizada' => 'ins-chip-ok', 'rechazada' => 'ins-chip-error', 'cancelada' => 'ins-chip-neutro'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-horarios.titulo :titulo="$esZona ? __('Solicitudes de cambio') : __('Mis solicitudes')"
                           :detalle="$esZona ? __('Autoriza o rechaza; al autorizar se modifica el horario') : auth()->user()->estacion?->nombre" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-horarios.nav activo="solicitudes" />

            <x-insumos.avisos />

            <div class="flex flex-wrap items-center gap-2">
                @foreach ($estados as $e)
                    <a href="{{ route('horarios.solicitudes.index', ['estado' => $e]) }}" @if ($e === $estado) aria-current="page" @endif
                       class="{{ $e === $estado ? 'ins-chip-marca underline' : 'ins-chip-neutro' }} hover:underline">{{ __($etiquetas[$e]) }} ({{ $conteos[$e] ?? 0 }})</a>
                @endforeach
            </div>

            @php $loteAnterior = null; @endphp
            @forelse ($solicitudes as $s)
                @if ($esZona && $estado === 'pendiente' && $s->lote && $s->lote !== $loteAnterior)
                    <div class="ins-card ins-card-pad space-y-3 !border-brand-teal/40" x-data="{ rechazando: false }">
                        <div>
                            <p class="font-heading text-base font-semibold text-gray-900">{{ $s->estacion?->nombre }} · {{ trans_choice(':n cambio pendiente|:n cambios pendientes', $tamanoLote[$s->lote] ?? 1, ['n' => $tamanoLote[$s->lote] ?? 1]) }}</p>
                            <p class="text-sm text-gray-600">{{ __('Enviados juntos por') }} {{ $s->solicitante?->name ?? '—' }} · {{ $s->created_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-gray-900">{{ $s->motivo }}</p>
                        </div>
                        <div class="flex flex-wrap items-start gap-2">
                            <form method="POST" action="{{ route('horarios.lotes.autorizar', $s->lote) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <input type="text" name="comentario" maxlength="1000" class="ins-campo w-56" placeholder="{{ __('Comentario (opcional)') }}" aria-label="{{ __('Comentario al autorizar el envío') }}: {{ $s->estacion?->nombre }}, {{ $s->created_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}">
                                <button type="submit" class="ins-btn-primario">{{ __('Autorizar todo el envío') }}<span class="sr-only">: {{ $s->estacion?->nombre }}, {{ $s->created_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</span></button>
                            </form>
                            <button type="button" class="ins-btn-peligro" @click="rechazando = ! rechazando" :aria-expanded="rechazando">{{ __('Rechazar todo el envío') }}<span class="sr-only">: {{ $s->estacion?->nombre }}, {{ $s->created_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</span></button>
                            <form method="POST" action="{{ route('horarios.lotes.rechazar', $s->lote) }}" x-show="rechazando" x-cloak class="flex w-full flex-wrap items-center gap-2">
                                @csrf
                                <input type="text" name="comentario" maxlength="1000" required class="ins-campo min-w-[16rem] flex-1" placeholder="{{ __('Explica por qué se rechaza') }}" aria-label="{{ __('Motivo del rechazo del envío') }}: {{ $s->estacion?->nombre }}, {{ $s->created_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}">
                                <button type="submit" class="ins-btn-peligro">{{ __('Confirmar rechazo') }}</button>
                            </form>
                        </div>
                    </div>
                @endif
                @php $loteAnterior = $s->lote; @endphp
                <article class="ins-card ins-card-pad space-y-3" x-data="{ rechazando: false }">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="font-heading text-base font-semibold text-gray-900">{{ $s->nombre }} <span class="text-sm font-normal text-gray-500">· {{ $s->no_empleado }}</span></p>
                            <p class="text-sm text-gray-600">{{ $s->estacion?->nombre }} · {{ $s->fecha->locale('es')->translatedFormat('l j \d\e F Y') }}</p>
                        </div>
                        <span class="{{ $chips[$s->estado] ?? 'ins-chip-neutro' }}">{{ __($s->etiquetaEstado()) }}</span>
                    </div>

                    <dl class="grid gap-3 text-sm sm:grid-cols-3">
                        <div><dt class="ins-etiqueta">{{ __('Ahora') }}</dt><dd class="mt-0.5 whitespace-pre-line text-gray-900">{{ $s->jornada_origen !== '' ? $s->jornada_origen : '—' }}</dd></div>
                        <div>
                            <dt class="ins-etiqueta">{{ $s->tipo === 'cambio_fecha' ? __('Se intercambia con') : __('Jornada propuesta') }}</dt>
                            <dd class="mt-0.5 text-gray-900">
                                @if ($s->tipo === 'cambio_fecha')
                                    {{ $s->nueva_fecha?->locale('es')->translatedFormat('l j \d\e F Y') }}
                                @else
                                    {{ $s->nueva_jornada }}@if ($s->nueva_comida) <span class="text-gray-500">· {{ __('Comida') }}: {{ $s->nueva_comida }}</span>@endif
                                @endif
                            </dd>
                        </div>
                        <div><dt class="ins-etiqueta">{{ __('Pidió') }}</dt><dd class="mt-0.5 text-gray-900">{{ $s->solicitante?->name ?? '—' }} · {{ $s->created_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</dd></div>
                        <div class="sm:col-span-3"><dt class="ins-etiqueta">{{ __('Motivo') }}</dt><dd class="mt-0.5 whitespace-pre-line text-gray-900">{{ $s->motivo }}</dd></div>
                        @if ($s->resuelta_at && $s->estado !== 'cancelada')
                            <div class="sm:col-span-3">
                                <dt class="ins-etiqueta">{{ __('Resolvió') }}</dt>
                                <dd class="mt-0.5 text-gray-900">{{ $s->resolvio?->name ?? '—' }} · {{ $s->resuelta_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}@if ($s->comentario_resolucion) — {{ $s->comentario_resolucion }}@endif</dd>
                            </div>
                        @endif
                        @if ($s->estado === 'autorizada' && ! $s->aplicada)
                            <div class="sm:col-span-3"><p class="ins-aviso-alerta">{{ __('Este cambio ya no se pudo aplicar al horario vigente (la persona o la fecha ya no existen).') }}</p></div>
                        @endif
                    </dl>

                    @can('resolver', $s)
                        <div class="flex flex-wrap items-start gap-2 border-t border-gray-100 pt-3">
                            <form method="POST" action="{{ route('horarios.solicitudes.autorizar', $s) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <input type="text" name="comentario" maxlength="1000" class="ins-campo w-56" placeholder="{{ __('Comentario (opcional)') }}" aria-label="{{ __('Comentario al autorizar') }}: {{ $s->nombre }}, {{ $s->fecha->format('d/m/Y') }}">
                                <button type="submit" class="ins-btn-primario">{{ __('Autorizar') }}<span class="sr-only">: {{ $s->nombre }}, {{ $s->fecha->format('d/m/Y') }}</span></button>
                            </form>
                            <button type="button" class="ins-btn-peligro" @click="rechazando = ! rechazando" :aria-expanded="rechazando">{{ __('Rechazar') }}<span class="sr-only">: {{ $s->nombre }}, {{ $s->fecha->format('d/m/Y') }}</span></button>
                            <form method="POST" action="{{ route('horarios.solicitudes.rechazar', $s) }}" x-show="rechazando" x-cloak class="flex w-full flex-wrap items-center gap-2">
                                @csrf
                                <input type="text" name="comentario" maxlength="1000" required class="ins-campo min-w-[16rem] flex-1" placeholder="{{ __('Explica por qué se rechaza') }}" aria-label="{{ __('Motivo del rechazo') }}: {{ $s->nombre }}, {{ $s->fecha->format('d/m/Y') }}">
                                <button type="submit" class="ins-btn-peligro">{{ __('Confirmar rechazo') }}<span class="sr-only">: {{ $s->nombre }}, {{ $s->fecha->format('d/m/Y') }}</span></button>
                            </form>
                        </div>
                    @endcan

                    @can('cancelar', $s)
                        <form method="POST" action="{{ route('horarios.solicitudes.cancelar', $s) }}" class="border-t border-gray-100 pt-3">
                            @csrf
                            <button type="submit" class="ins-btn-neutro">{{ __('Cancelar solicitud') }}<span class="sr-only">: {{ $s->nombre }}, {{ $s->fecha->format('d/m/Y') }}</span></button>
                        </form>
                    @endcan
                </article>
            @empty
                <div class="ins-card ins-card-pad text-center text-sm text-gray-600">{{ __('No hay solicitudes en este estado.') }}</div>
            @endforelse

            {{ $solicitudes->links() }}
        </div>
    </div>
</x-app-layout>
