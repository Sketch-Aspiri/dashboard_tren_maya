@php($equipo = $equipo ?? null)
<div>
    <x-input-label for="nombre" :value="__('Nombre del equipo')" />
    <input id="nombre" name="nombre" type="text" required maxlength="100" value="{{ old('nombre', $equipo?->nombre) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
    <x-input-error :messages="$errors->get('nombre')" class="mt-2" />
</div>

@if ($equipo === null)
    <div>
        <x-input-label for="clave" :value="__('Clave (opcional)')" />
        <input id="clave" name="clave" type="text" maxlength="32" value="{{ old('clave') }}" placeholder="calidad" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
        <p class="mt-1 text-xs text-gray-500">{{ __('Identifica al equipo en el Portal y en Tickets y no se puede cambiar después. Si la dejas vacía sale del nombre.') }}</p>
        <x-input-error :messages="$errors->get('clave')" class="mt-2" />
    </div>
@else
    <p class="text-sm text-gray-500">{{ __('Clave') }}: <span class="font-mono">{{ $equipo->clave }}</span></p>
@endif

<fieldset>
    <legend class="text-sm font-medium text-gray-700">{{ __('Estaciones donde existe este equipo') }}</legend>
    <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        @php($marcadas = collect(old('estaciones', $equipo?->estaciones->pluck('id')->all() ?? []))->map(fn ($v) => (int) $v))
        @foreach ($estaciones as $estacion)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="estaciones[]" value="{{ $estacion->id }}" @checked($marcadas->contains($estacion->id))>
                {{ $estacion->nombre }}
            </label>
        @endforeach
    </div>
    <x-input-error :messages="$errors->get('estaciones')" class="mt-2" />
</fieldset>

<fieldset>
    <legend class="text-sm font-medium text-gray-700">{{ __('Secciones que usa (grupos del menú)') }}</legend>
    <p class="text-xs text-gray-500">{{ __('Un grupo pertenece a un solo equipo: si lo marcas aquí se le quita al equipo que lo tenía. Un grupo sin equipo lo ven todas las cuentas de estación.') }}</p>
    <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        @php($gruposMarcados = collect(old('grupos', $equipo?->menuGrupos->pluck('id')->all() ?? []))->map(fn ($v) => (int) $v))
        @foreach ($grupos as $grupo)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="grupos[]" value="{{ $grupo->id }}" @checked($gruposMarcados->contains($grupo->id))>
                {{ $grupo->nombre }}
            </label>
        @endforeach
    </div>
    <x-input-error :messages="$errors->get('grupos')" class="mt-2" />
</fieldset>
