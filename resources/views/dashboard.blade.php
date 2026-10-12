<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green sm:text-2xl">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            <div class="rounded-xl border border-l-4 border-brand-green/10 border-l-brand-teal bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="text-sm text-gray-600">{{ __('Sesión activa') }}</p>
                <p class="mt-1 font-heading text-lg font-semibold text-brand-green">
                    {{ __('Bienvenido(a),') }} {{ Auth::user()->name }}
                </p>
            </div>

            <div>
                <h3 class="mb-3 font-heading text-sm font-semibold uppercase tracking-wide text-gray-600">
                    {{ __('Indicadores clave') }}
                </h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($kpis as $kpi)
                        <x-kpi-card :label="$kpi['label']" :value="$kpi['value']" :hint="$kpi['hint']" />
                    @endforeach
                </div>
            </div>

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="font-heading text-sm font-semibold uppercase tracking-wide text-gray-600">
                        {{ __('Actualizado recientemente') }}
                    </h3>
                    @can('create', \App\Models\Seccion::class)
                        <a href="{{ route('secciones.administrar') }}" class="text-sm font-medium text-brand-teal hover:text-brand-green-dark">
                            {{ __('Administrar menú') }}
                        </a>
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
                                   class="flex min-h-[48px] items-center justify-between gap-3 py-2 hover:bg-brand-mist/40">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-brand-green">{{ $reciente['seccion']->nombre }}</span>
                                        <span class="block text-xs text-gray-500">{{ $reciente['seccion']->grupo->nombre }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs text-gray-500">{{ $reciente['actualizada']->diffForHumans() }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                <h3 class="font-heading text-sm font-semibold uppercase tracking-wide text-gray-600">
                    {{ __('Accesos directos') }}
                </h3>
                <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <a href="{{ route('agenda.personal.index') }}"
                       class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-brand-green/20 bg-white px-4 py-2 text-sm font-medium text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                        {{ __('Personal') }}
                    </a>
                    @can('view-asistencia-zona')
                        <a href="{{ route('asistencia.zona.index') }}"
                           class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-brand-green/20 bg-white px-4 py-2 text-sm font-medium text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            {{ __('Asistencia Zona Oriente') }}
                        </a>
                    @endcan
                    @hasrole('Administrador')
                        <a href="{{ route('asistencia.captura.index') }}"
                           class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-brand-green/20 bg-white px-4 py-2 text-sm font-medium text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            {{ __('Captura de asistencia') }}
                        </a>
                    @endhasrole
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
