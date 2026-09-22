@php
    /** @var \App\Models\EscaleraElectrica $escalera */
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Controles') }} — {{ __('Editar escalera eléctrica') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="mb-4 text-sm text-gray-500">{{ $escalera->estacion->nombre }}</p>

                <form method="POST" action="{{ route('controles.escaleras-electricas.update', $escalera) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="identificador" :value="__('ID Escalera')" />
                            <x-text-input id="identificador" name="identificador" type="text" class="block mt-1 w-full"
                                          :value="old('identificador', $escalera->identificador)" />
                            <x-input-error :messages="$errors->get('identificador')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="modelo" :value="__('Modelo')" />
                            <x-text-input id="modelo" name="modelo" type="text" class="block mt-1 w-full"
                                          :value="old('modelo', $escalera->modelo)" />
                            <x-input-error :messages="$errors->get('modelo')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="anio_instalacion" :value="__('Año de instalación')" />
                            <x-text-input id="anio_instalacion" name="anio_instalacion" type="number" min="2000" max="2100" class="block mt-1 w-full"
                                          :value="old('anio_instalacion', $escalera->anio_instalacion)" />
                            <x-input-error :messages="$errors->get('anio_instalacion')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="tipo" :value="__('Tipo (ASC./DESC.)')" />
                            <x-text-input id="tipo" name="tipo" type="text" class="block mt-1 w-full"
                                          :value="old('tipo', $escalera->tipo)" />
                            <x-input-error :messages="$errors->get('tipo')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="operativo" :value="__('Operativo')" />
                            <x-text-input id="operativo" name="operativo" type="text" class="block mt-1 w-full"
                                          :value="old('operativo', $escalera->operativo)" />
                            <x-input-error :messages="$errors->get('operativo')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="fecha_ultimo_mantenimiento" :value="__('Último mantenimiento')" />
                            <x-text-input id="fecha_ultimo_mantenimiento" name="fecha_ultimo_mantenimiento" type="date" class="block mt-1 w-full"
                                          :value="old('fecha_ultimo_mantenimiento', $escalera->fecha_ultimo_mantenimiento?->format('Y-m-d'))" />
                            <x-input-error :messages="$errors->get('fecha_ultimo_mantenimiento')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="estado_barandales" :value="__('Estado de barandales')" />
                            <x-text-input id="estado_barandales" name="estado_barandales" type="text" class="block mt-1 w-full"
                                          :value="old('estado_barandales', $escalera->estado_barandales)" />
                            <x-input-error :messages="$errors->get('estado_barandales')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="estado_boton_paro_emergencia" :value="__('Estado de botón paro emergencia')" />
                            <x-text-input id="estado_boton_paro_emergencia" name="estado_boton_paro_emergencia" type="text" class="block mt-1 w-full"
                                          :value="old('estado_boton_paro_emergencia', $escalera->estado_boton_paro_emergencia)" />
                            <x-input-error :messages="$errors->get('estado_boton_paro_emergencia')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="observaciones" :value="__('Observaciones')" />
                        <textarea id="observaciones" name="observaciones" rows="4"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">{{ old('observaciones', $escalera->observaciones) }}</textarea>
                        <x-input-error :messages="$errors->get('observaciones')" class="mt-2" />
                    </div>

                    <div class="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:justify-end">
                        <a href="{{ route('controles.escaleras-electricas.index') }}" class="inline-flex min-h-[44px] items-center justify-center text-sm text-gray-600 hover:text-brand-green sm:self-center">{{ __('Cancelar') }}</a>
                        <x-primary-button class="w-full sm:w-auto">{{ __('Guardar') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
