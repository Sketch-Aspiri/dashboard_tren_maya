@php
    $etiquetas = ['pendiente' => 'Pendientes', 'autorizada' => 'Autorizadas', 'rechazada' => 'Rechazadas', 'cancelada' => 'Canceladas'];
    $chips = ['pendiente' => 'ins-chip-info', 'autorizada' => 'ins-chip-ok', 'rechazada' => 'ins-chip-error', 'cancelada' => 'ins-chip-neutro'];
    $rango = fn ($i, $f) => $i === null ? __('sin fechas') : $i->format('d/m/Y').' al '.$f->format('d/m/Y');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-vacaciones.titulo :titulo="$esZona ? __('Solicitudes de cambio') : __('Mis solicitudes')"
                             :detalle="$esZona ? __('Autoriza o rechaza cada envío; al autorizar se modifica el rol') : auth()->user()->estacion?->nombre" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-vacaciones.nav activo="solicitudes" />

            <x-insumos.avisos />
            @error('lote') <p class="ins-aviso-error">{{ $message }}</p> @enderror
            @error('tramos') <p class="ins-aviso-error">{{ $message }}</p> @enderror
            @error('comentario') <p class="ins-aviso-error">{{ $message }}</p> @enderror

            <div class="flex flex-wrap items-center gap-2">
                @foreach ($estados as $e)
                    <a href="{{ route('vacaciones.solicitudes.index', ['estado' => $e]) }}" @if ($e === $estado) aria-current="page" @endif
                       class="{{ $e === $estado ? 'ins-chip-marca' : 'ins-chip-neutro' }} hover:underline">{{ __($etiquetas[$e]) }} ({{ $conteos[$e] ?? 0 }})</a>
                @endforeach
            </div>

            @forelse ($lotes as $fila)
                @php $items = $solicitudes[$fila->lote] ?? collect(); $primera = $items->first(); @endphp
                @continue($primera === null)
                <article class="ins-card ins-card-pad space-y-3" x-data="{ rechazando: false }">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="font-heading text-base font-semibold text-gray-900">{{ $primera->estacion?->nombre }} · {{ trans_choice(':n cambio|:n cambios', $items->count(), ['n' => $items->count()]) }}</p>
                            <p class="text-sm text-gray-600">{{ $primera->solicitante?->name ?? '—' }} · {{ $primera->created_at->setTimezone('America/Cancun')->format('d/m/Y H:i') }}</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-gray-900">{{ $primera->motivo }}</p>
                        </div>
                        <span class="{{ $chips[$estado] }}">{{ __(rtrim($etiquetas[$estado], 's')) }}</span>
                    </div>

                    <div class="ins-tabla-marco">
                        <table class="ins-tabla">
                            <thead><tr><th scope="col">{{ __('Persona') }}</th><th scope="col">{{ __('Trimestre') }}</th><th scope="col">{{ __('Antes') }}</th><th scope="col">{{ __('Propuesto') }}</th></tr></thead>
                            <tbody>
                                @foreach ($items as $s)
                                    <tr>
                                        <td class="fuerte">{{ $s->nombre }}<span class="block text-xs font-normal text-gray-500">{{ $s->no_empleado }}</span></td>
                                        <td>{{ $s->trimestre }}º</td>
                                        <td>{{ $rango($s->inicio_origen, $s->fin_origen) }} <span class="text-xs text-gray-500">({{ $s->dias_origen }})</span></td>
                                        <td>{{ $rango($s->nuevo_inicio, $s->nuevo_fin) }} <span class="text-xs text-gray-500">({{ $s->nuevos_dias }})</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($primera->comentario_resolucion)
                        <p class="text-sm text-gray-700"><span class="font-semibold">{{ __('Comentario de la Zona:') }}</span> {{ $primera->comentario_resolucion }}</p>
                    @endif

                    @if ($estado === 'pendiente')
                        @if ($esZona)
                            <div class="flex flex-wrap items-start gap-2">
                                <form method="POST" action="{{ route('vacaciones.lotes.autorizar', $fila->lote) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    <input type="text" name="comentario" maxlength="1000" class="ins-campo w-56" placeholder="{{ __('Comentario (opcional)') }}" aria-label="{{ __('Comentario al autorizar el envío') }}">
                                    <button type="submit" class="ins-btn-primario">{{ __('Autorizar todo el envío') }}</button>
                                </form>
                                <button type="button" class="ins-btn-peligro" @click="rechazando = ! rechazando" :aria-expanded="rechazando">{{ __('Rechazar todo el envío') }}</button>
                                <form method="POST" action="{{ route('vacaciones.lotes.rechazar', $fila->lote) }}" x-show="rechazando" x-cloak class="flex w-full flex-wrap items-center gap-2">
                                    @csrf
                                    <input type="text" name="comentario" maxlength="1000" required class="ins-campo min-w-[16rem] flex-1" placeholder="{{ __('Explica por qué se rechaza') }}" aria-label="{{ __('Motivo del rechazo del envío') }}">
                                    <button type="submit" class="ins-btn-peligro">{{ __('Confirmar rechazo') }}</button>
                                </form>
                            </div>
                        @else
                            <form method="POST" action="{{ route('vacaciones.lotes.cancelar', $fila->lote) }}">
                                @csrf
                                <button type="submit" class="ins-btn-neutro">{{ __('Cancelar envío') }}</button>
                            </form>
                        @endif
                    @endif
                </article>
            @empty
                <div class="ins-card ins-card-pad text-sm text-gray-600">{{ __('No hay solicitudes en este estado.') }}</div>
            @endforelse

            {{ $lotes->links() }}
        </div>
    </div>
</x-app-layout>
