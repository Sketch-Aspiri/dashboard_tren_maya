@php
    /** @var \App\Models\Empleado|null $empleado */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Estacion> $estaciones */
@endphp

{{-- Fields follow the exact column order of the source Excel sheet ("Base
     de Datos Zona Oriente"), left to right, top to bottom — do not reorder
     or regroup without checking with the Jefe de Zona first. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="estacion_codigo" :value="__('No. Estación')" />
        <x-text-input id="estacion_codigo" name="estacion_codigo" type="text" class="block mt-1 w-full"
                      :value="old('estacion_codigo', $empleado?->estacion_codigo)" />
        <x-input-error :messages="$errors->get('estacion_codigo')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="estacion_id" :value="__('Estación (vinculación real — define en qué estación pasa lista)')" />
        <select id="estacion_id" name="estacion_id" class="mt-1 block w-full min-h-[44px] rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">
            <option value="">{{ __('— Sin vincular —') }}</option>
            @foreach ($estaciones as $estacion)
                <option value="{{ $estacion->id }}" @selected((int) old('estacion_id', $empleado?->estacion_id) === $estacion->id)>
                    {{ $estacion->nombre }}
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('estacion_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="plaza_actual" :value="__('Plaza actual')" />
        <x-text-input id="plaza_actual" name="plaza_actual" type="text" class="block mt-1 w-full"
                      :value="old('plaza_actual', $empleado?->plaza_actual)" />
        <x-input-error :messages="$errors->get('plaza_actual')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="no_empleado" :value="__('No. Empleado')" />
        <x-text-input id="no_empleado" name="no_empleado" type="text" class="block mt-1 w-full"
                      :value="old('no_empleado', $empleado?->no_empleado)" />
        <x-input-error :messages="$errors->get('no_empleado')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="estatus" :value="__('Estatus')" />
        <select id="estatus" name="estatus" class="mt-1 block w-full min-h-[44px] rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected(old('estatus', $empleado?->estatus?->value) === $status->value)>
                    {{ $status->label() }}
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('estatus')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="nombre_completo" :value="__('Nombre completo')" />
        <x-text-input id="nombre_completo" name="nombre_completo" type="text" class="block mt-1 w-full"
                      :value="old('nombre_completo', $empleado?->nombre_completo)" />
        <x-input-error :messages="$errors->get('nombre_completo')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="puesto" :value="__('Puesto')" />
        <x-text-input id="puesto" name="puesto" type="text" class="block mt-1 w-full"
                      :value="old('puesto', $empleado?->puesto)" />
        <x-input-error :messages="$errors->get('puesto')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="nivel_plaza" :value="__('Nivel de plaza')" />
        <x-text-input id="nivel_plaza" name="nivel_plaza" type="text" class="block mt-1 w-full"
                      :value="old('nivel_plaza', $empleado?->nivel_plaza)" />
        <x-input-error :messages="$errors->get('nivel_plaza')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="ultimo_grado_estudios" :value="__('Último grado de estudios')" />
        <x-text-input id="ultimo_grado_estudios" name="ultimo_grado_estudios" type="text" class="block mt-1 w-full"
                      :value="old('ultimo_grado_estudios', $empleado?->ultimo_grado_estudios)" />
        <x-input-error :messages="$errors->get('ultimo_grado_estudios')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="titulo" :value="__('Título')" />
        <x-text-input id="titulo" name="titulo" type="text" class="block mt-1 w-full"
                      :value="old('titulo', $empleado?->titulo)" />
        <x-input-error :messages="$errors->get('titulo')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="cedula" :value="__('Cédula')" />
        <x-text-input id="cedula" name="cedula" type="text" class="block mt-1 w-full"
                      :value="old('cedula', $empleado?->cedula)" />
        <x-input-error :messages="$errors->get('cedula')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="fecha_ingreso" :value="__('Fecha de ingreso')" />
        <x-text-input id="fecha_ingreso" name="fecha_ingreso" type="date" class="block mt-1 w-full"
                      :value="old('fecha_ingreso', optional($empleado?->fecha_ingreso)->toDateString())" />
        <x-input-error :messages="$errors->get('fecha_ingreso')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="telefono" :value="__('No. Telefónico')" />
        <x-text-input id="telefono" name="telefono" type="text" class="block mt-1 w-full"
                      :value="old('telefono', $empleado?->telefono)" />
        <x-input-error :messages="$errors->get('telefono')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="correo" :value="__('Correo')" />
        <x-text-input id="correo" name="correo" type="email" class="block mt-1 w-full"
                      :value="old('correo', $empleado?->correo)" />
        <x-input-error :messages="$errors->get('correo')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="tipo_sangre" :value="__('Tipo de sangre')" />
        <x-text-input id="tipo_sangre" name="tipo_sangre" type="text" class="block mt-1 w-full"
                      :value="old('tipo_sangre', $empleado?->tipo_sangre)" />
        <x-input-error :messages="$errors->get('tipo_sangre')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="alergias" :value="__('Alergias')" />
        <textarea id="alergias" name="alergias" rows="2"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">{{ old('alergias', $empleado?->alergias) }}</textarea>
        <x-input-error :messages="$errors->get('alergias')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="fecha_nacimiento" :value="__('Fecha de nacimiento')" />
        <x-text-input id="fecha_nacimiento" name="fecha_nacimiento" type="date" class="block mt-1 w-full"
                      :value="old('fecha_nacimiento', optional($empleado?->fecha_nacimiento)->toDateString())" />
        <x-input-error :messages="$errors->get('fecha_nacimiento')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="lugar_nacimiento" :value="__('Lugar de nacimiento')" />
        <x-text-input id="lugar_nacimiento" name="lugar_nacimiento" type="text" class="block mt-1 w-full"
                      :value="old('lugar_nacimiento', $empleado?->lugar_nacimiento)" />
        <x-input-error :messages="$errors->get('lugar_nacimiento')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="estado_civil" :value="__('Estado civil')" />
        <x-text-input id="estado_civil" name="estado_civil" type="text" class="block mt-1 w-full"
                      :value="old('estado_civil', $empleado?->estado_civil)" />
        <x-input-error :messages="$errors->get('estado_civil')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="curp" :value="__('CURP')" />
        <x-text-input id="curp" name="curp" type="text" maxlength="18" class="block mt-1 w-full"
                      :value="old('curp', $empleado?->curp)" />
        <x-input-error :messages="$errors->get('curp')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="rfc" :value="__('RFC')" />
        <x-text-input id="rfc" name="rfc" type="text" maxlength="13" class="block mt-1 w-full"
                      :value="old('rfc', $empleado?->rfc)" />
        <x-input-error :messages="$errors->get('rfc')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="nss" :value="__('NSS')" />
        <x-text-input id="nss" name="nss" type="text" maxlength="11" class="block mt-1 w-full"
                      :value="old('nss', $empleado?->nss)" />
        <x-input-error :messages="$errors->get('nss')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="domicilio" :value="__('Domicilio')" />
        <textarea id="domicilio" name="domicilio" rows="2"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">{{ old('domicilio', $empleado?->domicilio) }}</textarea>
        <x-input-error :messages="$errors->get('domicilio')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="contacto_emergencia_nombre" :value="__('Contacto de emergencia — Nombre')" />
        <x-text-input id="contacto_emergencia_nombre" name="contacto_emergencia_nombre" type="text" class="block mt-1 w-full"
                      :value="old('contacto_emergencia_nombre', $empleado?->contacto_emergencia_nombre)" />
        <x-input-error :messages="$errors->get('contacto_emergencia_nombre')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="contacto_emergencia_telefono" :value="__('Contacto de emergencia — Teléfono')" />
        <x-text-input id="contacto_emergencia_telefono" name="contacto_emergencia_telefono" type="text" class="block mt-1 w-full"
                      :value="old('contacto_emergencia_telefono', $empleado?->contacto_emergencia_telefono)" />
        <x-input-error :messages="$errors->get('contacto_emergencia_telefono')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="desempeno" :value="__('Desempeño')" />
        <textarea id="desempeno" name="desempeno" rows="3"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-teal focus:ring-brand-teal">{{ old('desempeno', $empleado?->desempeno) }}</textarea>
        <x-input-error :messages="$errors->get('desempeno')" class="mt-2" />
    </div>
</div>
