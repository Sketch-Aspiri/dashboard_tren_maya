@php
    /** @var \App\Models\Empleado $empleado */
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Agenda Zona Oriente') }} — {{ $empleado->nombre_completo ?? __('(Vacante)') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                {{-- Fields follow the exact column order of the source Excel
                     sheet ("Base de Datos Zona Oriente"), left to right, top
                     to bottom — do not reorder or regroup without checking
                     with the Jefe de Zona first. --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('No. Estación') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->estacion_codigo ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Estación (vinculación real)') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->estacion?->nombre ?? __('Sin vincular') }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Plaza actual') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->plaza_actual ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('No. Empleado') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->no_empleado ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Estatus') }}</p>
                        <p class="mt-0.5">
                            <span @class([
                                'inline-flex rounded-full px-2 py-1 text-xs font-semibold',
                                'bg-brand-mist text-brand-green' => $empleado->estatus->value === 'activo',
                                'bg-amber-100 text-amber-800' => $empleado->estatus->value !== 'activo',
                            ])>{{ $empleado->estatus->label() }}</span>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Nombre completo') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->nombre_completo ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Puesto') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->puesto ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Nivel de plaza') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->nivel_plaza ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Último grado de estudios') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->ultimo_grado_estudios ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Título') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->titulo ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Cédula') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->cedula ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Fecha de ingreso') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->fecha_ingreso?->toDateString() ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Meses en activo') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->meses_en_activo ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('No. Telefónico') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->telefono ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Correo') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->correo ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Tipo de sangre') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->tipo_sangre ?? '—' }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-sm font-medium text-gray-500">{{ __('Alergias') }}</p>
                        <p class="text-sm text-gray-900 whitespace-pre-line">{{ $empleado->alergias ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Edad') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->edad_en_numero ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Fecha de nacimiento') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->fecha_nacimiento?->toDateString() ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Lugar de nacimiento') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->lugar_nacimiento ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Estado civil') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->estado_civil ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('CURP') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->curp ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('RFC') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->rfc ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('NSS') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->nss ?? '—' }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-sm font-medium text-gray-500">{{ __('Domicilio') }}</p>
                        <p class="text-sm text-gray-900 whitespace-pre-line">{{ $empleado->domicilio ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Contacto de emergencia — Nombre') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->contacto_emergencia_nombre ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Contacto de emergencia — Teléfono') }}</p>
                        <p class="text-sm text-gray-900">{{ $empleado->contacto_emergencia_telefono ?? '—' }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-sm font-medium text-gray-500">{{ __('Desempeño') }}</p>
                        <p class="text-sm text-gray-900 whitespace-pre-line">{{ $empleado->desempeno ?? '—' }}</p>
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap items-center gap-4 border-t pt-6">
                    <a href="{{ route('agenda.personal.index') }}" class="inline-flex min-h-[44px] items-center text-sm text-gray-600 hover:text-brand-green">{{ __('Volver al listado') }}</a>
                    @can('update', $empleado)
                        <a href="{{ route('agenda.personal.edit', $empleado) }}" class="inline-flex min-h-[44px] items-center text-sm font-medium text-brand-teal hover:text-brand-green-dark">{{ __('Editar') }}</a>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
