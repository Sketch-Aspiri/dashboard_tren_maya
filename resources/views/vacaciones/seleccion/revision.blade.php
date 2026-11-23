@php
    $etiquetas = ['enviada' => __('Por revisar'), 'autorizada' => __('Autorizadas'), 'rechazada' => __('Rechazadas'), 'borrador' => __('Sin enviar')];
    $chips = ['enviada' => 'ins-chip-info', 'autorizada' => 'ins-chip-ok', 'rechazada' => 'ins-chip-error', 'borrador' => 'ins-chip-neutro'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-vacaciones.titulo :titulo="__('Selección de vacaciones :anio', ['anio' => $anio])"
                             :detalle="$esZona ? __('Autoriza o rechaza lo que envía cada persona') : auth()->user()->estacion?->nombre" />
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <x-vacaciones.nav activo="seleccion" />
            <x-insumos.avisos />
            @error('plan') <p class="ins-aviso-error">{{ $message }}</p> @enderror
            @error('comentario') <p class="ins-aviso-error">{{ $message }}</p> @enderror

            <div class="flex flex-wrap items-center gap-2">
                @foreach ($estados as $e)
                    <a href="{{ route('vacaciones.seleccion.revision', ['estado' => $e]) }}" @if ($e === $estado) aria-current="page" @endif
                       class="{{ $e === $estado ? 'ins-chip-marca' : 'ins-chip-neutro' }} hover:underline">{{ $etiquetas[$e] }} ({{ $conteos[$e] ?? 0 }})</a>
                @endforeach
            </div>

            @forelse ($planes as $estacion => $items)
                <section class="space-y-3">
                    <h3 class="font-heading text-lg font-semibold text-gray-900">{{ $estacion }}</h3>
                    @foreach ($items as $plan)
                        <article class="ins-card ins-card-pad space-y-3" x-data="{ rechazando: false }">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="font-semibold text-gray-900">{{ $plan->empleado?->nombre_completo }}</p>
                                    <p class="text-xs text-gray-500">{{ $plan->empleado?->no_empleado }} · {{ __(':x de :n días', ['x' => $plan->diasElegidos(), 'n' => $plan->dias_correspondientes]) }}</p>
                                </div>
                                <span class="{{ $chips[$plan->estado] }}">{{ $etiquetas[$plan->estado] }}</span>
                            </div>

                            <ul class="space-y-1 text-sm text-gray-800">
                                @forelse ($plan->selecciones->sortBy('trimestre') as $s)
                                    <li>{{ $s->trimestre }}º · {{ $s->periodo->etiqueta() }} <span class="text-xs text-gray-500">({{ $s->periodo->dias }} {{ __('días') }})</span></li>
                                @empty
                                    <li class="text-gray-500">{{ __('Sin periodos elegidos.') }}</li>
                                @endforelse
                            </ul>

                            @if ($plan->comentario)
                                <p class="text-sm text-gray-700"><span class="font-semibold">{{ __('Comentario:') }}</span> {{ $plan->comentario }}</p>
                            @endif

                            @if ($esZona && $plan->estado === 'enviada')
                                <div class="flex flex-wrap items-start gap-2">
                                    <form method="POST" action="{{ route('vacaciones.seleccion.autorizar', $plan) }}" class="flex flex-wrap items-center gap-2">
                                        @csrf
                                        <input type="text" name="comentario" maxlength="500" class="ins-campo w-56" placeholder="{{ __('Comentario (opcional)') }}" aria-label="{{ __('Comentario al autorizar') }}">
                                        <button type="submit" class="ins-btn-primario">{{ __('Autorizar') }}</button>
                                    </form>
                                    <button type="button" class="ins-btn-peligro" @click="rechazando = ! rechazando" :aria-expanded="rechazando">{{ __('Rechazar') }}</button>
                                    <form method="POST" action="{{ route('vacaciones.seleccion.rechazar', $plan) }}" x-show="rechazando" x-cloak class="flex w-full flex-wrap items-center gap-2">
                                        @csrf
                                        <input type="text" name="comentario" maxlength="500" required class="ins-campo min-w-[16rem] flex-1" placeholder="{{ __('Qué debe cambiar la persona') }}" aria-label="{{ __('Motivo del rechazo') }}">
                                        <button type="submit" class="ins-btn-peligro">{{ __('Confirmar rechazo') }}</button>
                                    </form>
                                </div>
                            @endif

                            @if ($esZona && $plan->editable())
                                <form method="POST" action="{{ route('vacaciones.seleccion.dias', $plan) }}" class="flex flex-wrap items-center gap-2 text-sm">
                                    @csrf
                                    @method('PATCH')
                                    <label for="dias-{{ $plan->id }}" class="text-gray-600">{{ __('Días que le corresponden') }}</label>
                                    <select id="dias-{{ $plan->id }}" name="dias" class="ins-campo w-24">
                                        @foreach ([0, 5, 10, 15, 20, 25, 30] as $d)
                                            <option value="{{ $d }}" @selected($plan->dias_correspondientes === $d)>{{ $d }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="ins-btn-neutro">{{ __('Ajustar') }}</button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </section>
            @empty
                <div class="ins-card ins-card-pad text-sm text-gray-600">{{ __('No hay selecciones en este estado.') }}</div>
            @endforelse
        </div>
    </div>
</x-app-layout>
