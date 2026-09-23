@php
    /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\EstatusViaAnden>> $porEstacion */
    /** @var \Illuminate\Support\Collection<int, bool> $puedeEditar */
    $clasesDeEstatus = function (?string $estatus): string {
        $normalizado = mb_strtoupper($estatus ?? '');

        return match (true) {
            str_contains($normalizado, 'FUERA') || str_contains($normalizado, 'NO OPERATIV') => 'bg-red-100 text-red-700',
            str_contains($normalizado, 'OPERATIV') || str_contains($normalizado, 'BUEN') => 'bg-brand-mint/30 text-brand-green',
            default => 'bg-brand-mist text-gray-700',
        };
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Controles') }} — {{ __('Estatus de vías y andenes') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6" x-data="{ q: '' }">
            @if (session('status'))
                <div class="rounded-xl border border-brand-green/10 bg-brand-mist p-4 text-sm text-brand-green">
                    {{ session('status') }}
                </div>
            @endif

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm shadow-brand-green/5">
                <input
                    type="search"
                    x-model="q"
                    placeholder="{{ __('Buscar por estación...') }}"
                    class="min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-teal focus:ring-brand-teal sm:w-72"
                >
            </div>

            @forelse ($porEstacion as $nombreEstacion => $registros)
                <div
                    x-show="q.trim() === '' || @js(\Illuminate\Support\Str::lower($nombreEstacion)).includes(q.trim().toLowerCase())"
                    class="overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5"
                >
                    <div class="border-b border-brand-green/10 px-4 py-3">
                        <h3 class="font-heading text-lg font-semibold text-brand-green">{{ $nombreEstacion }}</h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-green/10 text-sm">
                            <thead class="bg-brand-mist/60">
                                <tr>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Vía') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Andén') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Estatus del andén') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Estatus de la vía') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Señalética') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Teleindicadores') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Pruebas de gálibo') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Riesgos / obstáculos') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Comentarios específicos') }}</th>
                                    <th scope="col" class="px-3 py-3 text-right font-heading font-semibold text-brand-green">{{ __('Acciones') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-green/10">
                                @foreach ($registros as $registro)
                                    <tr class="hover:bg-brand-mist/30 align-top">
                                        <td class="whitespace-nowrap px-3 py-3 font-medium text-gray-900">{{ $registro->via }}</td>
                                        <td class="whitespace-nowrap px-3 py-3 text-gray-700">{{ $registro->anden ?? '—' }}</td>
                                        <td class="min-w-[9rem] px-3 py-3">
                                            @if ($registro->estatus_anden === null)
                                                <span class="text-gray-500">—</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $clasesDeEstatus($registro->estatus_anden) }}">
                                                    {{ $registro->estatus_anden }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="min-w-[9rem] px-3 py-3">
                                            @if ($registro->estatus_via === null)
                                                <span class="text-gray-500">—</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $clasesDeEstatus($registro->estatus_via) }}">
                                                    {{ $registro->estatus_via }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="min-w-[10rem] px-3 py-3 text-gray-700">{{ $registro->senaletica ?? '—' }}</td>
                                        <td class="min-w-[10rem] px-3 py-3 text-gray-700">{{ $registro->teleindicadores ?? '—' }}</td>
                                        <td class="px-3 py-3 text-gray-700">{{ $registro->pruebas_galibo ?? '—' }}</td>
                                        <td class="min-w-[16rem] whitespace-pre-line px-3 py-3 text-gray-600">{{ $registro->riesgos_obstaculos ?? '—' }}</td>
                                        <td class="min-w-[16rem] whitespace-pre-line px-3 py-3 text-gray-600">{{ $registro->comentarios ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-3 text-right">
                                            @if ($puedeEditar[$registro->estacion_id] ?? false)
                                                <a href="{{ route('controles.estatus-vias-andenes.edit', $registro) }}" class="font-medium text-brand-teal hover:text-brand-green-dark">
                                                    {{ __('Editar') }}
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-6 text-center text-sm text-gray-500 shadow-sm shadow-brand-green/5">
                    {{ __('No hay estatus de vías y andenes registrados.') }}
                </div>
            @endforelse

            @if ($porEstacion->isNotEmpty())
                <div
                    x-show="q.trim() !== '' && ! @js($porEstacion->keys()->map(fn ($nombre) => \Illuminate\Support\Str::lower($nombre))).some(nombre => nombre.includes(q.trim().toLowerCase()))"
                    class="overflow-hidden rounded-xl border border-brand-green/10 bg-white p-6 text-center text-sm text-gray-500 shadow-sm shadow-brand-green/5"
                >
                    {{ __('Ninguna estación coincide con la búsqueda.') }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
