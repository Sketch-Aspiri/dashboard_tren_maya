@php
    /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\EscaleraElectrica>> $porEstacion */
    /** @var \Illuminate\Support\Collection<int, bool> $puedeEditar */
    $esOperativa = function (?string $operativo): bool {
        if ($operativo === null) {
            return false;
        }
        $normalizado = mb_strtoupper($operativo);

        return str_starts_with($normalizado, 'SI') && ! str_contains($normalizado, 'FUERA') && ! str_contains($normalizado, 'NO OPER');
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Recursos materiales') }} — {{ __('Escaleras eléctricas') }}
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

            @forelse ($porEstacion as $nombreEstacion => $escaleras)
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
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('ID') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Modelo') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Año') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Tipo') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Operativo') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Barandales') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Botón de paro') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Último mantenimiento') }}</th>
                                    <th scope="col" class="px-3 py-3 text-left font-heading font-semibold text-brand-green">{{ __('Observaciones') }}</th>
                                    <th scope="col" class="px-3 py-3 text-right font-heading font-semibold text-brand-green">{{ __('Acciones') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-green/10">
                                @foreach ($escaleras as $escalera)
                                    <tr class="hover:bg-brand-mist/30 align-top">
                                        <td class="px-3 py-3 text-gray-700">{{ $escalera->identificador ?? '—' }}</td>
                                        <td class="px-3 py-3 text-gray-700">{{ $escalera->modelo ?? '—' }}</td>
                                        <td class="px-3 py-3 text-gray-700">{{ $escalera->anio_instalacion ?? '—' }}</td>
                                        <td class="px-3 py-3 text-gray-700">{{ $escalera->tipo ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-3">
                                            @if ($escalera->operativo === null)
                                                <span class="text-gray-500">—</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $esOperativa($escalera->operativo) ? 'bg-brand-mint/30 text-brand-green' : 'bg-red-100 text-red-700' }}">
                                                    {{ $escalera->operativo }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-gray-700">{{ $escalera->estado_barandales ?? '—' }}</td>
                                        <td class="px-3 py-3 text-gray-700">{{ $escalera->estado_boton_paro_emergencia ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-3 text-gray-700">{{ $escalera->fecha_ultimo_mantenimiento?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="min-w-[16rem] whitespace-pre-line px-3 py-3 text-gray-600">{{ $escalera->observaciones ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-3 text-right">
                                            @if ($puedeEditar[$escalera->estacion_id] ?? false)
                                                <a href="{{ route('controles.escaleras-electricas.edit', $escalera) }}" class="font-medium text-brand-teal hover:text-brand-green-dark">
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
                    {{ __('No hay escaleras eléctricas registradas.') }}
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
