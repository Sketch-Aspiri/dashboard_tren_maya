<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ $grupo }} — {{ $label }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="rounded-xl border border-dashed border-brand-green/20 bg-white/60 p-8 text-center shadow-sm shadow-brand-green/5 sm:p-12">
                <p class="font-heading text-base font-semibold text-brand-green">
                    {{ __('Pendiente de agregar información') }}
                </p>
                <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                    {{ __('Esta sección ya está en el menú según el esquema del dashboard; su contenido se cargará cuando se entregue la información.') }}
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
