<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Nuevo Example') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('examples.store') }}" class="space-y-4">
                    @csrf

                    @include('examples.partials.form-fields', ['example' => null])

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('examples.index') }}" class="text-sm text-gray-600 self-center">{{ __('Cancelar') }}</a>
                        <x-primary-button>{{ __('Guardar') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
