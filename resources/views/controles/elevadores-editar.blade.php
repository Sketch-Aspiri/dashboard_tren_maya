@php
    /** @var \App\Models\Elevador $elevador */
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Recursos materiales') }} — {{ __('Editar elevador') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="mb-4 text-sm text-gray-500">{{ $elevador->estacion->nombre }}</p>

                <form method="POST" action="{{ route('controles.elevadores.update', $elevador) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="identificador" :value="__('ID Elevador')" />
                            <x-text-input id="identificador" name="identificador" type="text" class="block mt-1 w-full"
                                          :value="old('identificador', $elevador->identificador)" />
                            <x-input-error :messages="$errors->get('identificador')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="modelo" :value="__('Modelo')" />
                            <x-text-input id="modelo" name="modelo" type="text" class="block mt-1 w-full"
                                          :value="old('modelo', $elevador->modelo)" />
                            <x-input-error :messages="$errors->get('modelo')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="anio_instalacion" :value="__('Año de instalación')" />
                            <x-text-input id="anio_instalacion" name="anio_instalacion" type="number" min="2000" max="2100" class="block mt-1 w-full"
                                          :value="old('anio_instalacion', $elevador->anio_instalacion)" />
                            <x-input-error :messages="$errors->get('anio_instalacion')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="tipo" :value="__('Tipo')" />
                            <x-text-input id="tipo" name="tipo" type="text" class="block mt-1 w-full"
                                          :value="old('tipo', $elevador->tipo)" />
                            <x-input-error :messages="$errors->get('tipo')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="operativo" :value="__('Operativo')" />
                            <x-text-input id="operativo" name="operativo" type="text" class="block mt-1 w-full"
                                          :value="old('operativo', $elevador->operativo)" />
                            <x-input-error :messages="$errors->get('operativo')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="fecha_ultimo_mantenimiento" :value="__('Último mantenimiento')" />
                            <x-text-input id="fecha_ultimo_mantenimiento" name="fecha_ultimo_mantenimiento" type="date" class="block mt-1 w-full"
                                          :value="old('fecha_ultimo_mantenimiento', $elevador->fecha_ultimo_mantenimiento?->format('Y-m-d'))" />
                            <x-input-error :messages="$errors->get('fecha_ultimo_mantenimiento')" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="estado_puertas_cabina_botoneras" :value="__('Estado de puertas, cabina y botoneras')" />
                            <x-text-input id="estado_puertas_cabina_botoneras" name="estado_puertas_cabina_botoneras" type="text" class="block mt-1 w-full"
                                          :value="old('estado_puertas_cabina_botoneras', $elevador->estado_puertas_cabina_botoneras)" />
                            <x-input-error :messages="$errors->get('estado_puertas_cabina_botoneras')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="observaciones" :value="__('Observaciones')" />
                        <textarea id="observaciones" name="observaciones" rows="4"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">{{ old('observaciones', $elevador->observaciones) }}</textarea>
                        <x-input-error :messages="$errors->get('observaciones')" class="mt-2" />
                    </div>

                    <div class="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:justify-end">
                        <a href="{{ route('controles.elevadores.index') }}" class="inline-flex min-h-[44px] items-center justify-center text-sm text-gray-600 hover:text-brand-green sm:self-center">{{ __('Cancelar') }}</a>
                        <x-primary-button class="w-full sm:w-auto">{{ __('Guardar') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
