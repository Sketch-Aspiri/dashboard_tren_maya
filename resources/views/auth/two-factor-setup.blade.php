<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('La autenticación en dos pasos es obligatoria. Escanea este código con tu aplicación de autenticación (Google Authenticator, Authy, etc.) y confirma con el código de 6 dígitos.') }}
    </div>

    <div class="flex justify-center mb-4">
        {!! $qrCodeSvg !!}
    </div>

    <div class="mb-4 text-sm text-gray-600">
        {{ __('¿No puedes escanear el código? Ingresa esta clave manualmente:') }}
        <code class="block mt-1 p-2 bg-gray-100 rounded text-xs break-all">{{ $secretKey }}</code>
    </div>

    <form method="POST" action="{{ route('two-factor.setup.store') }}">
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
                {{ __('Confirmar y activar') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
