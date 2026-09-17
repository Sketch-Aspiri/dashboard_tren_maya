@php
    /** @var \App\Models\Estacion $estacion */
    /** @var \Carbon\CarbonImmutable $fecha */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Empleado> $roster */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\ComisionadoVisitante> $comisionadosVisitantes */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\ComisionadoFuera> $comisionadosFuera */
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Asistencia Zona Oriente') }} — {{ $estacion->nombre }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="flex flex-col gap-2 rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                <p class="text-sm text-gray-600">
                    {{ __('Fecha') }}: <span class="font-medium text-brand-green">{{ $fecha->translatedFormat('d \d\e F \d\e Y') }}</span>
                </p>
                <a href="{{ route('asistencia.zona.index', ['fecha' => $fecha->toDateString()]) }}"
                   class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-brand-green/20 bg-white px-4 py-2 text-sm font-medium text-brand-green transition duration-150 ease-in-out hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                    {{ __('Volver al tablero') }}
                </a>
            </div>

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6">
                <h3 class="mb-3 font-heading text-base font-semibold text-brand-green">
                    {{ __('Roster del día (solo lectura)') }}
                </h3>

                @if ($roster->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('Esta estación no tiene personal activo registrado.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <th class="py-2 pr-4">{{ __('Nombre') }}</th>
                                    <th class="py-2 pr-4">{{ __('Puesto') }}</th>
                                    <th class="py-2 pr-4">{{ __('Estatus') }}</th>
                                    <th class="py-2 pr-4">{{ __('Fecha inicio') }}</th>
                                    <th class="py-2 pr-4">{{ __('Fecha fin') }}</th>
                                    <th class="py-2 pr-4">{{ __('Notas') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($roster as $empleado)
                                    @php
                                        $registroHoy = $empleado->registrosDiarios->first();
                                        $estatus = $registroHoy?->estatus;
                                    @endphp
                                    <tr class="align-top">
                                        <td class="py-2 pr-4 font-medium text-gray-800">{{ $empleado->nombre_completo }}</td>
                                        <td class="py-2 pr-4 text-gray-600">{{ $empleado->puesto }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($estatus === null)
                                                <span class="inline-flex min-h-[28px] items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                                                    {{ __('Sin capturar') }}
                                                </span>
                                            @else
                                                <span class="inline-flex min-h-[28px] items-center rounded-full px-3 py-1 text-xs font-semibold
                                                             {{ $estatus === \App\Enums\EstatusAsistencia::Presente ? 'bg-brand-mist text-brand-green' : 'bg-amber-100 text-amber-800' }}">
                                                    {{ $estatus->label() }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-gray-600">{{ optional($registroHoy?->fecha_inicio)->toDateString() }}</td>
                                        <td class="py-2 pr-4 text-gray-600">{{ optional($registroHoy?->fecha_fin)->toDateString() }}</td>
                                        <td class="py-2 pr-4 text-gray-600">{{ $registroHoy?->notas }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6 space-y-4">
                <h3 class="font-heading text-base font-semibold text-brand-green">
                    {{ __('Personal comisionado de otras coordinaciones presentes hoy') }}
                </h3>

                @if ($comisionadosVisitantes->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('Sin registros para esta fecha.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($comisionadosVisitantes as $visitante)
                            <li class="py-2">
                                <p class="font-medium text-gray-800">{{ $visitante->nombre }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $visitante->direccion_origen }}
                                    @if ($visitante->motivo) &mdash; {{ $visitante->motivo }} @endif
                                    @if ($visitante->fecha_inicio && $visitante->fecha_fin)
                                        ({{ $visitante->fecha_inicio->toDateString() }} &ndash; {{ $visitante->fecha_fin->toDateString() }})
                                    @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5 sm:p-6 space-y-4">
                <h3 class="font-heading text-base font-semibold text-brand-green">
                    {{ __('Personal de Zona Oriente comisionado fuera hoy') }}
                </h3>

                @if ($comisionadosFuera->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('Sin registros para esta fecha.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($comisionadosFuera as $comisionado)
                            <li class="py-2">
                                <p class="font-medium text-gray-800">{{ $comisionado->empleado?->nombre_completo }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $comisionado->coordinacion_destino }}
                                    @if ($comisionado->ubicacion_destino) &mdash; {{ $comisionado->ubicacion_destino }} @endif
                                    @if ($comisionado->motivo) &mdash; {{ $comisionado->motivo }} @endif
                                    @if ($comisionado->fecha_inicio && $comisionado->fecha_fin)
                                        ({{ $comisionado->fecha_inicio->toDateString() }} &ndash; {{ $comisionado->fecha_fin->toDateString() }})
                                    @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
