<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">{{ __('Foto de perfil') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('Es la misma foto en el portal, el Dashboard y Tickets: si la cambias aquí, se actualiza en los demás.') }}</p>
    </header>

    <div class="mt-6 flex flex-wrap items-center gap-6">
        <x-foto-perfil :user="$user" tam="h-20 w-20 text-2xl" />

        <div class="space-y-3">
            <form method="POST" action="{{ route('profile.foto.update') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <x-input-label for="foto" :value="__('Elegir una foto (JPG o PNG)')" />
                <input id="foto" name="foto" type="file" required accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="block w-full text-sm text-gray-800">
                <x-input-error :messages="$errors->get('foto')" />
                <x-primary-button>{{ __('Guardar foto') }}</x-primary-button>
                @if (session('status') === 'foto-updated')
                    <p data-aviso class="text-sm text-gray-600">{{ __('Foto actualizada.') }}</p>
                @endif
            </form>

            @if ($user->avatar_path)
                <form method="POST" action="{{ route('profile.foto.destroy') }}">
                    @csrf
                    @method('delete')
                    <button type="submit" class="text-sm font-medium text-red-700 underline">{{ __('Quitar foto') }}</button>
                </form>
            @endif
        </div>
    </div>
</section>
