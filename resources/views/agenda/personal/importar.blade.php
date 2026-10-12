<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green">
            {{ __('RR.HH.') }} — {{ __('Importar personal') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="mx-auto max-w-3xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="rounded-xl border border-brand-green/10 bg-white p-6 shadow-sm shadow-brand-green/5">
                <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('Vista previa') }}</h3>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __('Se leyeron las hojas "Base de Datos Zona Oriente" y "Cuadrillas de Mantenimiento". Las personas se identifican por su número de empleado: las que ya existen se actualizan y las nuevas se agregan. Nadie se elimina.') }}
                </p>

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <x-kpi-card :label="__('Nuevos')" :value="number_format($resumen['creados'])" />
                    <x-kpi-card :label="__('Actualizados')" :value="number_format($resumen['actualizados'])" />
                    <x-kpi-card :label="__('Total después')" :value="number_format($resumen['total_despues'])" />
                </div>

                <p class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="note">
                    {{ __('Esta importación no se puede deshacer automáticamente: revisa las cifras antes de aplicar. Las correcciones posteriores se hacen en cada ficha de Personal.') }}
                </p>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <x-confirmar-eliminar :action="route('agenda.personal.importar.destroy')"
                                          :etiqueta="__('Descartar importación')" :confirmar="__('Sí, descartar')"
                                          :pregunta="__('¿Descartar? No se cambiará nada.')" />
                    <form method="POST" action="{{ route('agenda.personal.importar.aplicar') }}" x-data="{ enviando: false }" @submit="if (enviando) { $event.preventDefault() } enviando = true">
                        @csrf
                        <x-primary-button class="w-full sm:w-auto" x-bind:disabled="enviando">
                            <span x-show="! enviando">{{ __('Aplicar importación') }}</span>
                            <span x-show="enviando" x-cloak>{{ __('Aplicando…') }}</span>
                        </x-primary-button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
