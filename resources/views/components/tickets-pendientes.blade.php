{{-- "Mis pendientes en Tickets" (ecosistema Zona Oriente). Los datos vienen de
     App\Services\Ecosistema\TicketsPendientes; títulos y folios son datos de
     usuario de Tickets: siempre escapados con {{ }}. Sin puente configurado
     ($pendientes = null) la tarjeta no se muestra. --}}
@props(['pendientes' => null])

@if ($pendientes !== null)
    <section class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6" aria-labelledby="titulo-pendientes-tickets">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 id="titulo-pendientes-tickets" class="font-heading text-base font-semibold text-brand-green">{{ __('Mis pendientes en Tickets') }}</h3>
                <p class="text-sm text-gray-600">{{ __('Lo que tienes asignado en el sistema de Tickets') }}</p>
            </div>
        </div>

        @if (! $pendientes['disponible'])
            <p class="mt-4 rounded-lg bg-brand-mist px-3 py-2 text-sm text-gray-600" role="status">{{ __('Tickets no está disponible por el momento. Vuelve a intentarlo en unos minutos.') }}</p>
        @elseif (! $pendientes['vinculada'])
            <p class="mt-4 text-sm text-gray-600">{{ __('Para ver aquí tus pendientes hay que unir tus cuentas: cierra sesión en los dos sistemas y vuelve a entrar. En cada uno te pedirá tu contraseña una sola vez.') }}</p>
            <a href="{{ $pendientes['url'] }}" class="mt-4 inline-flex min-h-[44px] items-center font-semibold text-brand-teal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('Ir a Tickets') }} <span aria-hidden="true">&nbsp;→</span></a>
        @else
            @php
                $conteos = $pendientes['conteos'];
                $tarjetas = [
                    ['valor' => $conteos['tickets'], 'texto' => __('Tickets')],
                    ['valor' => $conteos['actividades'], 'texto' => __('Actividades')],
                    ['valor' => $conteos['vencidos'], 'texto' => __('Vencidos'), 'alerta' => $conteos['vencidos'] > 0],
                    ['valor' => $conteos['en_revision'], 'texto' => __('En revisión')],
                ];
            @endphp
            <dl class="mt-4 grid grid-cols-2 gap-3">
                @foreach ($tarjetas as $tarjeta)
                    <div @class(['rounded-lg px-3 py-2', 'bg-red-50' => $tarjeta['alerta'] ?? false, 'bg-brand-mist' => ! ($tarjeta['alerta'] ?? false)])>
                        <dt class="text-xs text-gray-600">{{ $tarjeta['texto'] }}</dt>
                        <dd @class(['font-heading text-2xl font-bold tabular-nums', 'text-red-600' => $tarjeta['alerta'] ?? false, 'text-brand-green' => ! ($tarjeta['alerta'] ?? false)])>{{ $tarjeta['valor'] }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($pendientes['urgentes'] === [])
                <p class="mt-4 text-sm text-gray-600">{{ __('No tienes pendientes en Tickets. ¡Todo al día!') }}</p>
            @else
                <h4 class="mt-5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Lo más urgente') }}</h4>
                <ul class="mt-2 divide-y divide-gray-100">
                    @foreach ($pendientes['urgentes'] as $fila)
                        <li>
                            <a href="{{ $fila['url'] }}" class="flex min-h-[44px] items-start gap-3 rounded-md py-2 hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-xs font-semibold text-brand-teal">{{ $fila['folio'] }} · {{ $fila['tipo'] === 'actividad' ? __('Actividad') : __('Ticket') }}</span>
                                    <span class="block truncate text-sm text-gray-900">{{ $fila['titulo'] }}</span>
                                    <span class="block text-xs text-gray-500">{{ $fila['estado'] }} · {{ $fila['prioridad'] }}</span>
                                </span>
                                @if ($fila['vence'])
                                    <span @class(['shrink-0 text-xs tabular-nums', 'font-semibold text-red-600' => $fila['vencido'], 'text-gray-500' => ! $fila['vencido']])>
                                        @if ($fila['vencido'])<span class="sr-only">{{ __('Vencido:') }}</span>@endif
                                        {{ \Illuminate\Support\Carbon::parse($fila['vence'])->translatedFormat('d M') }}
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <a href="{{ $pendientes['url'] }}"
               class="mt-5 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-md bg-brand-green px-4 text-sm font-semibold text-white transition hover:bg-brand-green-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2">
                {{ __('Abrir Mis pendientes en Tickets') }}
            </a>
        @endif
    </section>
@endif
