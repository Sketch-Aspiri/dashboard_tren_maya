<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('Ingresa el código de 6 dígitos de tu aplicación de autenticación para continuar.') }}
    </div>

    <form method="POST" action="{{ route('two-factor.verify.store') }}">
        @csrf

        <div>
            <x-input-label for="one_time_password" :value="__('Código de verificación')" />

            <x-text-input id="one_time_password" class="block mt-1 w-full"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            name="one_time_password"
                            required autofocus />

            <x-input-error :messages="$errors->get('one_time_password')" class="mt-2" />
        </div>

        <div class="flex justify-end mt-4">
            <x-primary-button>
                {{ __('Verificar') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
