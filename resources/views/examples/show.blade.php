<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Example') }} #{{ $example->id }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <div>
                    <p class="text-sm font-medium text-gray-500">{{ __('Nombre') }}</p>
                    <p class="text-gray-900">{{ $example->name }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">{{ __('Valor') }}</p>
                    <p class="text-gray-900">{{ $example->value ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">{{ __('Estado') }}</p>
                    <p class="text-gray-900">{{ $example->status->label() }}</p>
                </div>

                <div class="pt-4 border-t">
                    <a href="{{ route('examples.index') }}" class="text-sm text-gray-600">{{ __('Volver al listado') }}</a>
                    @can('update', $example)
                        <a href="{{ route('examples.edit', $example) }}" class="ml-4 text-sm text-gray-600">{{ __('Editar') }}</a>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
