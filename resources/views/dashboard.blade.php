@php
    /** @var list<array<string, mixed>> $kpis */
    /** @var \Illuminate\Support\Collection<int, array<string, mixed>>|null $asistencia */
    /** @var array<string, int>|null $insumos */
    $ahora = now()->locale('es');
    $saludo = $ahora->hour < 12 ? __('Buenos días') : ($ahora->hour < 19 ? __('Buenas tardes') : __('Buenas noches'));
    $nombreCorto = \Illuminate\Support\Str::of(Auth::user()->name)->explode(' ')->first();

    if ($asistencia) {
        $conPersonal = $asistencia->where('total_roster', '>', 0);
        $pendientes = $conPersonal->where('capturado', false);
        $avanceAsistencia = $conPersonal->sum('total_roster') > 0
            ? (int) round(100 * $conPersonal->sum('total_capturado') / $conPersonal->sum('total_roster'))
            : 0;
    }

    if ($insumos) {
        $mesInsumos = \Carbon\CarbonImmutable::create($insumos['anio'], $insumos['mes'], 1)->locale('es')->isoFormat('MMMM YYYY');
        $segmentosInsumos = [
            ['clave' => 'aprobadas', 'texto' => __('Aprobadas'), 'color' => 'bg-brand-teal'],
            ['clave' => 'por_revisar', 'texto' => __('Por revisar'), 'color' => 'bg-blue-500'],
            ['clave' => 'observadas', 'texto' => __('Con observaciones'), 'color' => 'bg-amber-500'],
            ['clave' => 'borrador', 'texto' => __('En captura'), 'color' => 'bg-gray-500'],
            ['clave' => 'sin_iniciar', 'texto' => __('Sin iniciar'), 'color' => 'bg-gray-400'],
        ];
    }
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green sm:text-2xl">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            {{-- Bienvenida --}}
            <section class="relative overflow-hidden rounded-2xl bg-brand-green px-5 py-6 text-white shadow-sm sm:px-8 sm:py-7">
                <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-brand-mint/20 blur-2xl" aria-hidden="true"></div>
                <div class="pointer-events-none absolute -bottom-24 right-40 h-56 w-56 rounded-full bg-brand-teal/40 blur-2xl" aria-hidden="true"></div>
                <div class="relative flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-white/75 first-letter:uppercase">{{ $ahora->isoFormat('dddd D [de] MMMM [de] YYYY') }}</p>
                        <p class="mt-1 font-heading text-2xl font-bold sm:text-3xl">{{ $saludo }}, {{ $nombreCorto }}</p>
                        <p class="mt-1 text-sm text-white/80">{{ __('Resumen de la Zona Oriente') }}</p>
                    </div>
                    @if ($asistencia && $conPersonal->isNotEmpty())
                        <a href="{{ route('asistencia.zona.index') }}"
                           class="inline-flex min-h-[44px] items-center gap-2 self-start rounded-lg bg-white/10 px-4 text-sm font-semibold text-white ring-1 ring-inset ring-white/20 transition hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white sm:self-auto">
                            @if ($pendientes->isEmpty())
                                <span class="h-2 w-2 rounded-full bg-brand-mint" aria-hidden="true"></span>
                                {{ __('Asistencia de hoy completa') }}
                            @else
                                <span class="h-2 w-2 rounded-full bg-amber-400" aria-hidden="true"></span>
                                {{ trans_choice(':n estación sin capturar asistencia|:n estaciones sin capturar asistencia', $pendientes->count(), ['n' => $pendientes->count()]) }}
                            @endif
                        </a>
                    @endif
                </div>
            </section>

            {{-- Indicadores --}}
            <section aria-labelledby="titulo-indicadores">
                <h3 id="titulo-indicadores" class="mb-3 font-heading text-sm font-semibold uppercase tracking-wide text-gray-600">
                    {{ __('Indicadores clave') }}
                </h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($kpis as $kpi)
                        <x-kpi-card :label="$kpi['label']" :value="$kpi['value']" :hint="$kpi['hint']"
                                    :href="$kpi['href'] ?? null" :icono="$kpi['icono'] ?? null" :tono="$kpi['tono'] ?? null" />
                    @endforeach
                </div>
            </section>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    @if ($asistenteDisponible)
                        @include('dashboard.asistente')
                    @endif

                    {{-- Asistencia de hoy por estación --}}
                    @if ($asistencia && $asistencia->isNotEmpty())
                        <section class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6" aria-labelledby="titulo-asistencia-hoy">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 id="titulo-asistencia-hoy" class="font-heading text-base font-semibold text-brand-green">{{ __('Asistencia de hoy') }}</h3>
                                <a href="{{ route('asistencia.zona.index') }}" class="text-sm font-medium text-brand-teal hover:underline">{{ __('Ver control de asistencia') }}</a>
                            </div>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ __(':p % del personal con registro de hoy', ['p' => $avanceAsistencia]) }}
                            </p>
                            <ul class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                                @foreach ($asistencia as $fila)
                                    @php
                                        $porcentaje = $fila['total_roster'] > 0 ? (int) round(100 * $fila['total_capturado'] / $fila['total_roster']) : 0;
                                    @endphp
                                    <li>
                                        <a href="{{ route('asistencia.zona.show', ['estacion' => $fila['estacion']->id, 'fecha' => now()->toDateString()]) }}"
                                           class="-mx-2 block rounded-lg px-2 py-1.5 hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                                            <span class="flex items-center justify-between gap-2 text-sm">
                                                <span class="truncate font-medium text-gray-900">{{ $fila['estacion']->nombre }}</span>
                                                @if ($fila['total_roster'] === 0)
                                                    <span class="shrink-0 text-xs text-gray-500">{{ __('Sin personal') }}</span>
                                                @elseif ($fila['capturado'])
                                                    <span class="ins-chip-ok shrink-0">{{ __('Capturada') }}</span>
                                                @else
                                                    <span class="ins-chip-alerta shrink-0">{{ $fila['total_capturado'] }}/{{ $fila['total_roster'] }}</span>
                                                @endif
                                            </span>
                                            @if ($fila['total_roster'] > 0)
                                                <span class="dash-barra mt-1.5 block" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $porcentaje }}"
                                                      aria-label="{{ __('Asistencia capturada en :e', ['e' => $fila['estacion']->nombre]) }}">
                                                    <span class="block h-full rounded-full {{ $fila['capturado'] ? 'bg-brand-teal' : 'bg-amber-400' }}" style="width: {{ $porcentaje }}%"></span>
                                                </span>
                                            @endif
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    {{-- Actualizado recientemente --}}
                    <section class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6" aria-labelledby="titulo-recientes">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 id="titulo-recientes" class="font-heading text-base font-semibold text-brand-green">{{ __('Actualizado recientemente') }}</h3>
                            @can('create', \App\Models\Seccion::class)
                                <a href="{{ route('secciones.administrar') }}" class="text-sm font-medium text-brand-teal hover:underline">{{ __('Administrar menú') }}</a>
                            @endcan
                        </div>

                        @if ($recientes->isEmpty())
                            <div class="mt-4 rounded-lg border border-dashed border-brand-green/20 bg-brand-mist/50 p-6 text-center">
                                <p class="font-heading text-base font-semibold text-brand-green">{{ __('Todavía no hay información capturada') }}</p>
                                <p class="mx-auto mt-2 max-w-lg text-sm text-gray-600">
                                    {{ __('Abre cualquier sección del menú y arrastra un Excel, Word o PDF: si trae una tabla, el sistema la crea sola. También puedes capturar registros a mano.') }}
                                </p>
                            </div>
                        @else
                            <ul class="mt-3 divide-y divide-gray-100">
                                @foreach ($recientes as $reciente)
                                    <li>
                                        <a href="{{ route('secciones.show', $reciente['seccion']) }}"
                                           class="-mx-2 flex min-h-[52px] items-center gap-3 rounded-lg px-2 py-2 hover:bg-brand-mist/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                                            <span class="dash-icono h-9 w-9" aria-hidden="true">
                                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" clip-rule="evenodd" d="M4 4a2 2 0 012-2h4.59A2 2 0 0112 2.59L15.41 6A2 2 0 0116 7.41V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" /></svg>
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-medium text-gray-900">{{ $reciente['seccion']->nombre }}</span>
                                                <span class="block truncate text-xs text-gray-500">{{ $reciente['seccion']->grupo->nombre }}</span>
                                            </span>
                                            <span class="shrink-0 text-xs text-gray-500">{{ $reciente['actualizada']->diffForHumans() }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>

                <aside class="space-y-6">
                    {{-- Mis pendientes en Tickets (ecosistema) --}}
                    <x-tickets-pendientes :pendientes="$pendientesTickets ?? null" />

                    {{-- Insumos del mes --}}
                    @if ($insumos)
                        <section class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6" aria-labelledby="titulo-insumos-mes">
                            <h3 id="titulo-insumos-mes" class="font-heading text-base font-semibold text-brand-green">{{ __('Insumos') }}</h3>
                            <p class="text-sm capitalize text-gray-600">{{ $mesInsumos }}</p>

                            <p class="mt-4 font-heading text-3xl font-bold tabular-nums text-brand-green">
                                {{ $insumos['aprobadas'] }}<span class="text-lg font-semibold text-gray-400">/{{ $insumos['estaciones'] }}</span>
                            </p>
                            <p class="text-xs text-gray-500">{{ __('estaciones con solicitud aprobada') }}</p>

                            @if ($insumos['estaciones'] > 0)
                                <div class="dash-barra mt-4 flex h-2.5" aria-hidden="true">
                                    @foreach ($segmentosInsumos as $segmento)
                                        @if ($insumos[$segmento['clave']] > 0)
                                            <span class="{{ $segmento['color'] }} h-full" style="width: {{ 100 * $insumos[$segmento['clave']] / $insumos['estaciones'] }}%"></span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            <dl class="mt-4 space-y-1.5 text-sm">
                                @foreach ($segmentosInsumos as $segmento)
                                    <div class="flex items-center justify-between gap-2">
                                        <dt class="flex items-center gap-2 text-gray-600"><span class="{{ $segmento['color'] }} h-2.5 w-2.5 rounded-full" aria-hidden="true"></span>{{ $segmento['texto'] }}</dt>
                                        <dd class="font-semibold tabular-nums text-gray-900">{{ $insumos[$segmento['clave']] }}</dd>
                                    </div>
                                @endforeach
                            </dl>

                            <a href="{{ route('insumos.index', ['anio' => $insumos['anio'], 'mes' => $insumos['mes']]) }}"
                               class="mt-5 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-md bg-brand-green px-4 text-sm font-semibold text-white transition hover:bg-brand-green-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2">
                                @if ($insumos['por_revisar'] > 0)
                                    {{ trans_choice('Revisar :n solicitud|Revisar :n solicitudes', $insumos['por_revisar'], ['n' => $insumos['por_revisar']]) }}
                                @else
                                    {{ __('Abrir tablero de insumos') }}
                                @endif
                            </a>
                        </section>
                    @endif

                    {{-- Accesos directos --}}
                    <section aria-labelledby="titulo-accesos">
                        <h3 id="titulo-accesos" class="mb-3 font-heading text-sm font-semibold uppercase tracking-wide text-gray-600">{{ __('Accesos directos') }}</h3>
                        @php
                            $accesos = array_filter([
                                ['href' => route('agenda.personal.index'), 'texto' => __('Personal'), 'detalle' => __('Plantilla y datos del personal'), 'trazo' => 'M9 6a3 3 0 11-6 0 3 3 0 016 0zm8 0a3 3 0 11-6 0 3 3 0 016 0zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5zm8 0a5 5 0 015 5v1h-6v-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0114 11z'],
                                Auth::user()->can('view-asistencia-zona') ? ['href' => route('asistencia.zona.index'), 'texto' => __('Asistencia Zona Oriente'), 'detalle' => __('Control diario por estación'), 'trazo' => 'M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1z'] : null,
                                Auth::user()->hasRole('Administrador') ? ['href' => route('asistencia.captura.index'), 'texto' => __('Captura de asistencia'), 'detalle' => __('Registrar el día de hoy'), 'trazo' => 'M13.59 3.59a2 2 0 112.82 2.82l-8 8a2 2 0 01-.86.5l-3 .86a.5.5 0 01-.62-.62l.86-3a2 2 0 01.5-.86l8-8zM3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z'] : null,
                                Auth::user()->can('viewAny', \App\Models\Factura::class) ? ['href' => route('insumos.facturas.index'), 'texto' => __('Facturas'), 'detalle' => __('Comprobación del fondo rotatorio'), 'trazo' => 'M4 2a2 2 0 00-2 2v13l3-2 3 2 3-2 3 2 3-2V4a2 2 0 00-2-2H4zm2 4a1 1 0 000 2h6a1 1 0 100-2H6zm0 4a1 1 0 100 2h4a1 1 0 100-2H6z'] : null,
                            ]);
                        @endphp
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-1">
                            @foreach ($accesos as $acceso)
                                <a href="{{ $acceso['href'] }}" class="dash-acceso">
                                    <span class="dash-icono" aria-hidden="true">
                                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" clip-rule="evenodd" d="{{ $acceso['trazo'] }}" /></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-semibold text-brand-green">{{ $acceso['texto'] }}</span>
                                        <span class="block truncate text-xs text-gray-500">{{ $acceso['detalle'] }}</span>
                                    </span>
                                    <span class="text-gray-400" aria-hidden="true">→</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
