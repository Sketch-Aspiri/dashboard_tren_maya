<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('RR.HH.') }} — {{ __('Nuevo registro de personal') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="POST" action="{{ route('agenda.personal.store') }}" class="space-y-6">
                    @csrf

                    @include('agenda.personal.partials.form-fields', ['empleado' => null])

                    <div class="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:justify-end">
                        <a href="{{ route('agenda.personal.index') }}" class="inline-flex min-h-[44px] items-center justify-center text-sm text-gray-600 hover:text-brand-green sm:self-center">{{ __('Cancelar') }}</a>
                        <x-primary-button class="w-full sm:w-auto">{{ __('Guardar') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
