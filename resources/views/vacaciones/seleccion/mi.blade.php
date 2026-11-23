@php
    $chips = ['borrador' => 'ins-chip-neutro', 'enviada' => 'ins-chip-info', 'autorizada' => 'ins-chip-ok', 'rechazada' => 'ins-chip-error'];
    $nombres = ['borrador' => __('Borrador'), 'enviada' => __('Enviada a la Zona'), 'autorizada' => __('Autorizada'), 'rechazada' => __('Requiere cambios')];
    $editable = $plan->editable();
    $trimestres = [1 => __('Enero a marzo'), 2 => __('Abril a junio'), 3 => __('Julio a septiembre'), 4 => __('Octubre a diciembre')];
    $elegidosDias = $plan->diasElegidos();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-teal">{{ __('RR.HH.') }}</p>
            <h2 class="mt-0.5 truncate font-heading text-xl font-semibold leading-tight text-brand-green">{{ __('Mis vacaciones :anio', ['anio' => $anio]) }}</h2>
            <p class="mt-0.5 truncate text-sm text-gray-500">{{ $plan->estacion?->nombre }}</p>
        </div>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <x-insumos.avisos />
            @error('plan') <p class="ins-aviso-error">{{ $message }}</p> @enderror

            <section class="ins-card ins-card-pad space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="font-heading text-base font-semibold text-gray-900">{{ __('Te corresponden :n días hábiles', ['n' => $plan->dias_correspondientes]) }}</p>
                    <span class="{{ $chips[$plan->estado] }}">{{ $nombres[$plan->estado] }}</span>
                </div>
                <p class="text-sm text-gray-600">
                    {{ __('Elige como máximo un periodo por trimestre. Los periodos son de 5 o 10 días hábiles (ya sin días inhábiles). Llevas :x de :n días.', ['x' => $elegidosDias, 'n' => $plan->dias_correspondientes]) }}
                    {{ __('En tu estación pueden coincidir :t personas a la vez; los periodos llenos aparecen bloqueados.', ['t' => $tope]) }}
                </p>
                @if ($plan->estado === 'rechazada' && $plan->comentario)
                    <p class="text-sm text-gray-900"><span class="font-semibold">{{ __('Comentario de la Zona:') }}</span> {{ $plan->comentario }}</p>
                @endif
                @if ($plan->dias_correspondientes === 0)
                    <p class="ins-aviso-error">{{ __('No te corresponden vacaciones en este año. Si es un error, avisa a la Zona.') }}</p>
                @endif
            </section>

            @if ($catalogo->isEmpty())
                <p class="ins-card ins-card-pad text-sm text-gray-600">{{ __('La Zona aún no publica los periodos de :anio.', ['anio' => $anio]) }}</p>
            @else
                <form method="POST" action="{{ route('vacaciones.mi.guardar') }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    @foreach ($trimestres as $t => $nombreTrimestre)
                        <fieldset class="ins-card ins-card-pad" @disabled(! $editable)>
                            <legend class="px-1 font-heading text-base font-semibold text-gray-900">{{ $t }}º {{ __('trimestre') }} · {{ $nombreTrimestre }}</legend>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <label class="flex min-h-[44px] items-center gap-2 rounded border border-gray-200 px-3 py-2 text-sm">
                                    <input type="radio" name="periodos[{{ $t }}]" value="" @checked(! isset($elegidos[$t])) class="text-brand-green focus:ring-brand-teal">
                                    <span>{{ __('Ninguno en este trimestre') }}</span>
                                </label>
                                @foreach ($catalogo[$t] ?? [] as $p)
                                    @php
                                        $lleno = ($picos[$p->id] ?? 0) >= $tope;
                                        $marcado = ($elegidos[$t] ?? null) === $p->id;
                                        $bloqueado = ! $p->autorizado || ($lleno && ! $marcado);
                                    @endphp
                                    <label class="flex min-h-[44px] items-center gap-2 rounded border px-3 py-2 text-sm {{ $bloqueado ? 'border-gray-100 text-gray-400' : 'border-gray-200' }}">
                                        <input type="radio" name="periodos[{{ $t }}]" value="{{ $p->id }}" @checked($marcado) @disabled($bloqueado) class="text-brand-green focus:ring-brand-teal">
                                        <span class="flex-1">{{ $p->etiqueta() }}</span>
                                        <span class="text-xs">{{ $p->dias }} {{ __('días') }}</span>
                                        @if (! $p->autorizado)
                                            <span class="ins-chip-neutro">{{ $p->nota ?? __('No autorizado') }}</span>
                                        @elseif ($lleno && ! $marcado)
                                            <span class="ins-chip-error">{{ __('Lleno') }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach

                    @if ($editable)
                        <button type="submit" class="ins-btn-primario">{{ __('Guardar borrador') }}</button>
                    @endif
                </form>

                <div class="flex flex-wrap gap-3">
                    @if ($editable)
                        <form method="POST" action="{{ route('vacaciones.mi.enviar') }}">
                            @csrf
                            <button type="submit" class="ins-btn-secundario" @disabled($elegidosDias !== $plan->dias_correspondientes || $plan->dias_correspondientes === 0)>{{ __('Enviar a la Zona') }}</button>
                        </form>
                    @endif
                    @if ($plan->estado === 'enviada')
                        <form method="POST" action="{{ route('vacaciones.mi.retirar') }}">
                            @csrf
                            <button type="submit" class="ins-btn-secundario">{{ __('Retirar envío para corregir') }}</button>
                        </form>
                    @endif
                </div>
                @if ($editable && $elegidosDias !== $plan->dias_correspondientes)
                    <p class="text-sm text-gray-500">{{ __('Guarda tu borrador y completa los :n días para poder enviarlo.', ['n' => $plan->dias_correspondientes]) }}</p>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
