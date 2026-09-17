@php
    /** @var \App\Models\User|null $user */
    /** @var list<string> $roles */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Estacion> $estaciones */
    $selectedRole = old('role', $user?->roles?->first()?->name);
@endphp

{{-- x-model just toggles which fields are visible client-side — the
     estacion_id value is always submitted and the server (StoreUserRequest
     / UpdateUserRequest / UserManagementService) is the real source of
     truth for "estacion_id only applies to role Estación", never this
     toggle alone. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4" x-data="{ role: @js($selectedRole) }">
    <div>
        <x-input-label for="name" :value="__('Nombre')" />
        <x-text-input id="name" name="name" type="text" class="block mt-1 w-full"
                      :value="old('name', $user?->name)" required autofocus />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="email" :value="__('Correo electrónico')" />
        <x-text-input id="email" name="email" type="email" class="block mt-1 w-full"
                      :value="old('email', $user?->email)" required />
        <x-input-error :messages="$errors->get('email')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="role" :value="__('Rol')" />
        <select id="role" name="role" x-model="role" required
                class="mt-1 block w-full min-h-[44px] rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">
            <option value="">{{ __('Selecciona un rol') }}</option>
            @foreach ($roles as $roleName)
                <option value="{{ $roleName }}" @selected($selectedRole === $roleName)>{{ $roleName }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('role')" class="mt-2" />
    </div>

    <div x-show="role === 'Estación'" x-cloak>
        <x-input-label for="estacion_id" :value="__('Estación')" />
        <select id="estacion_id" name="estacion_id"
                class="mt-1 block w-full min-h-[44px] rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">
            <option value="">{{ __('Selecciona una estación') }}</option>
            @foreach ($estaciones as $estacion)
                <option value="{{ $estacion->id }}" @selected((string) old('estacion_id', $user?->estacion_id) === (string) $estacion->id)>
                    {{ $estacion->nombre }}
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('estacion_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="password" :value="$user ? __('Nueva contraseña') : __('Contraseña')" />
        <x-text-input id="password" name="password" type="password" class="block mt-1 w-full"
                      autocomplete="new-password" :required="! $user" />
        @if ($user)
            <p class="mt-1 text-xs text-gray-500">{{ __('Deja en blanco para no cambiar la contraseña.') }}</p>
        @endif
        <x-input-error :messages="$errors->get('password')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="password_confirmation" :value="__('Confirmar contraseña')" />
        <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="block mt-1 w-full"
                      autocomplete="new-password" :required="! $user" />
        @if ($user)
            <p class="mt-1 text-xs text-gray-500">{{ __('Dejar en blanco para no cambiar la contraseña.') }}</p>
        @endif
    </div>
</div>
