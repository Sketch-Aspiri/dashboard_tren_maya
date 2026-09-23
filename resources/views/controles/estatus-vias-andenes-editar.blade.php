@php
    /** @var \App\Models\EstatusViaAnden $registro */
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Controles') }} — {{ __('Editar estatus de vía y andén') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <p class="mb-4 text-sm text-gray-500">{{ $registro->estacion->nombre }} — {{ __('Vía') }} {{ $registro->via }}</p>

                <form method="POST" action="{{ route('controles.estatus-vias-andenes.update', $registro) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="anden" :value="__('Andén (A / B)')" />
                            <x-text-input id="anden" name="anden" type="text" maxlength="10" class="block mt-1 w-full"
                                          :value="old('anden', $registro->anden)" />
                            <x-input-error :messages="$errors->get('anden')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="pruebas_galibo" :value="__('Pruebas de gálibo')" />
                            <x-text-input id="pruebas_galibo" name="pruebas_galibo" type="text" class="block mt-1 w-full"
                                          :value="old('pruebas_galibo', $registro->pruebas_galibo)" />
                            <x-input-error :messages="$errors->get('pruebas_galibo')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="estatus_anden" :value="__('Estatus del andén')" />
                            <x-text-input id="estatus_anden" name="estatus_anden" type="text" class="block mt-1 w-full"
                                          :value="old('estatus_anden', $registro->estatus_anden)" />
                            <x-input-error :messages="$errors->get('estatus_anden')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="estatus_via" :value="__('Estatus de la vía')" />
                            <x-text-input id="estatus_via" name="estatus_via" type="text" class="block mt-1 w-full"
                                          :value="old('estatus_via', $registro->estatus_via)" />
                            <x-input-error :messages="$errors->get('estatus_via')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="senaletica" :value="__('Señalética')" />
                            <x-text-input id="senaletica" name="senaletica" type="text" class="block mt-1 w-full"
                                          :value="old('senaletica', $registro->senaletica)" />
                            <x-input-error :messages="$errors->get('senaletica')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="teleindicadores" :value="__('Teleindicadores')" />
                            <x-text-input id="teleindicadores" name="teleindicadores" type="text" class="block mt-1 w-full"
                                          :value="old('teleindicadores', $registro->teleindicadores)" />
                            <x-input-error :messages="$errors->get('teleindicadores')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="riesgos_obstaculos" :value="__('Riesgos / obstáculos')" />
                        <textarea id="riesgos_obstaculos" name="riesgos_obstaculos" rows="4"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">{{ old('riesgos_obstaculos', $registro->riesgos_obstaculos) }}</textarea>
                        <x-input-error :messages="$errors->get('riesgos_obstaculos')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="comentarios" :value="__('Comentarios específicos')" />
                        <textarea id="comentarios" name="comentarios" rows="4"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">{{ old('comentarios', $registro->comentarios) }}</textarea>
                        <x-input-error :messages="$errors->get('comentarios')" class="mt-2" />
                    </div>

                    <div class="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:justify-end">
                        <a href="{{ route('controles.estatus-vias-andenes.index') }}" class="inline-flex min-h-[44px] items-center justify-center text-sm text-gray-600 hover:text-brand-green sm:self-center">{{ __('Cancelar') }}</a>
                        <x-primary-button class="w-full sm:w-auto">{{ __('Guardar') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
