<x-guest-layout>
    <h1 class="font-heading text-xl font-bold text-brand-green sm:text-2xl">
        {{ __('Iniciar sesión') }}
    </h1>
    <p class="mt-1 text-sm text-gray-600">
        {{ __('Ingresa tus credenciales para acceder al panel.') }}
    </p>

    <!-- Session Status -->
    <x-auth-session-status class="mt-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-6">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Correo electrónico')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4" x-data="{ show: false }">
            <x-input-label for="password" :value="__('Contraseña')" />

            <div class="relative mt-1">
                <x-text-input id="password" class="block w-full pr-11"
                                type="password"
                                x-bind:type="show ? 'text' : 'password'"
                                name="password"
                                required autocomplete="current-password" />

                <button
                    type="button"
                    @click="show = !show"
                    x-bind:aria-label="show ? '{{ __('Ocultar contraseña') }}' : '{{ __('Mostrar contraseña') }}'"
                    x-bind:aria-pressed="show"
                    class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-md text-gray-500 hover:text-brand-green focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2"
                >
                    <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M10 3.5c-4.5 0-8.2 2.9-9.5 6.5 1.3 3.6 5 6.5 9.5 6.5s8.2-2.9 9.5-6.5C18.2 6.4 14.5 3.5 10 3.5Zm0 10.8a4.3 4.3 0 1 1 0-8.6 4.3 4.3 0 0 1 0 8.6Z" />
                        <path d="M10 8.1a1.9 1.9 0 1 0 0 3.8 1.9 1.9 0 0 0 0-3.8Z" />
                    </svg>
                    <svg x-show="show" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M3.28 2.22a.75.75 0 0 0-1.06 1.06l14.5 14.5a.75.75 0 1 0 1.06-1.06l-1.86-1.86c1.62-1.15 2.9-2.75 3.63-4.66-1.3-3.6-5-6.5-9.55-6.5-1.5 0-2.92.32-4.2.9L3.28 2.22ZM10 6.7c.4 0 .78.06 1.14.16L9.4 8.6a1.9 1.9 0 0 1-1.1-1.7c0-.09 0-.18.02-.26A4.3 4.3 0 0 1 10 6.7Zm-6.7 1.2a9.5 9.5 0 0 0-1.8 2.6c1.3 3.6 5 6.5 9.5 6.5.86 0 1.68-.1 2.46-.3l-1.7-1.7a4.3 4.3 0 0 1-5.16-5.16L3.3 7.9Z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="mt-4">
            <label for="remember_me" class="inline-flex min-h-[44px] cursor-pointer items-center gap-2">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-brand-teal shadow-sm focus:ring-brand-teal" name="remember">
                <span class="text-sm text-gray-700">{{ __('Recordarme') }}</span>
            </label>
        </div>

        <div class="mt-4 flex flex-col-reverse items-stretch gap-4 sm:flex-row sm:items-center sm:justify-between">
            @if (Route::has('password.request'))
                <a class="inline-flex min-h-[44px] items-center rounded-md text-sm text-brand-teal underline hover:text-brand-green focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2" href="{{ route('password.request') }}">
                    {{ __('¿Olvidaste tu contraseña?') }}
                </a>
            @endif

            <x-primary-button class="justify-center sm:justify-start">
                {{ __('Iniciar sesión') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
