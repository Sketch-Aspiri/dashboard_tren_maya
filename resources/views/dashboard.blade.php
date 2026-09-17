<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="text-sm text-gray-500">{{ __('Sesión activa') }}</p>
                <p class="mt-1 font-heading text-lg font-semibold text-brand-green">
                    {{ __('Bienvenido(a),') }} {{ Auth::user()->name }}
                </p>
            </div>

            <div>
                <h3 class="mb-3 font-heading text-sm font-semibold uppercase tracking-wide text-gray-500">
                    {{ __('Indicadores clave') }}
                </h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($kpis as $kpi)
                        <x-kpi-card :label="$kpi['label']" :value="$kpi['value']" :hint="$kpi['hint']" />
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-400">
                    {{ __('Los indicadores mostrados son de ejemplo. Los KPIs reales se definirán con el Jefe de Zona (ver CLAUDE.md).') }}
                </p>
            </div>

            <div>
                <h3 class="mb-3 font-heading text-sm font-semibold uppercase tracking-wide text-gray-500">
                    {{ __('Gráficas e indicadores') }}
                </h3>
                <div class="rounded-xl border border-dashed border-brand-green/20 bg-white/60 p-8 text-center shadow-sm shadow-brand-green/5 sm:p-12">
                    <p class="font-heading text-base font-semibold text-brand-green">
                        {{ __('Próximamente') }}
                    </p>
                    <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                        {{ __('Las gráficas interactivas (Chart.js / ApexCharts) se habilitarán una vez definidos los indicadores y dimensiones que necesita consultar el Jefe de Zona.') }}
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5 sm:p-6">
                <h3 class="font-heading text-sm font-semibold uppercase tracking-wide text-gray-500">
                    {{ __('Accesos directos') }}
                </h3>
                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('agenda.personal.index') }}"
                       class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-brand-green/20 bg-white px-4 py-2 text-sm font-medium text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                        {{ __('Agenda Zona Oriente') }}
                    </a>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
