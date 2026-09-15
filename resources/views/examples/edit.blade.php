<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Editar Example') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('examples.update', $example) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    @include('examples.partials.form-fields', ['example' => $example])

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('examples.index') }}" class="text-sm text-gray-600 self-center">{{ __('Cancelar') }}</a>
                        <x-primary-button>{{ __('Actualizar') }}</x-primary-button>
                    </div>
                </form>

                @can('delete', $example)
                    <form method="POST" action="{{ route('examples.destroy', $example) }}" class="mt-6 pt-6 border-t"
                          onsubmit="return confirm('{{ __('¿Eliminar este registro?') }}')">
                        @csrf
                        @method('DELETE')
                        <x-danger-button type="submit">{{ __('Eliminar') }}</x-danger-button>
                    </form>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
