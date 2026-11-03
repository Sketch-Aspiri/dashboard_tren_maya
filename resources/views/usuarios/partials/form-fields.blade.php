@php
    /** @var \App\Models\User|null $user */
    /** @var list<string> $roles */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Estacion> $estaciones */
    $selectedRoles = old('roles', $user?->roles?->pluck('name')->all() ?? []);
@endphp

{{-- x-model just toggles which fields are visible client-side — the
     estacion_id value is always submitted and the server (StoreUserRequest
     / UpdateUserRequest / UserManagementService) is the real source of
     truth for "estacion_id only applies to role Estación", never this
     toggle alone. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4" x-data="{ roles: @js(array_values($selectedRoles)) }">
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

    <fieldset>
        <legend class="block text-sm font-medium text-gray-700">{{ __('Roles') }}</legend>
        <div class="mt-1 space-y-2">
            @foreach ($roles as $roleName)
                <label class="flex min-h-[44px] items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="roles[]" value="{{ $roleName }}" x-model="roles"
                           @checked(in_array($roleName, $selectedRoles, true))
                           class="rounded border-gray-300 text-brand-teal shadow-sm focus:ring-brand-teal">
                    {{ $roleName }}
                </label>
            @endforeach
        </div>
        <p class="mt-1 text-xs text-gray-500">{{ __('Jefe de Zona y Administrador pueden combinarse. Edificio y Estación van solos.') }}</p>
        <x-input-error :messages="$errors->get('roles')" class="mt-2" />
        <x-input-error :messages="$errors->get('roles.*')" class="mt-2" />
    </fieldset>

    <div x-show="roles.includes('Estación')" x-cloak>
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
