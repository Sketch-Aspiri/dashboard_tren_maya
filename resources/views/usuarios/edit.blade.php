<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Gestión de usuarios') }} — {{ __('Editar cuenta') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <form method="POST" action="{{ route('usuarios.update', $user) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    @include('usuarios.partials.form-fields', ['user' => $user])

                    <div class="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:justify-end">
                        <a href="{{ route('usuarios.index') }}" class="inline-flex min-h-[44px] items-center justify-center text-sm text-gray-600 hover:text-brand-green sm:self-center">{{ __('Cancelar') }}</a>
                        <x-primary-button class="w-full sm:w-auto">{{ __('Actualizar') }}</x-primary-button>
                    </div>
                </form>

                @can('delete', $user)
                    <form method="POST" action="{{ route('usuarios.destroy', $user) }}" class="mt-6 border-t pt-6"
                          onsubmit="return confirm('{{ __('¿Eliminar esta cuenta? Esta acción no se puede deshacer.') }}')">
                        @csrf
                        @method('DELETE')
                        <x-danger-button type="submit" class="w-full sm:w-auto">{{ __('Eliminar cuenta') }}</x-danger-button>
                    </form>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
