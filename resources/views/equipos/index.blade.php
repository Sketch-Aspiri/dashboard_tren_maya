<x-app-layout>
    <x-slot name="header">
        <h1 class="font-heading text-xl font-semibold text-brand-green leading-tight">{{ __('Equipos por estación') }}</h1>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @foreach (['status' => 'border-brand-teal/20 bg-brand-mist text-brand-green', 'warning' => 'border-amber-300 bg-amber-50 text-amber-900'] as $tipo => $clases)
                @if (session($tipo))
                    <div class="rounded-md border p-4 text-sm {{ $clases }}">{{ session($tipo) }}</div>
                @endif
            @endforeach
            @error('equipo')
                <div class="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-800">{{ $message }}</div>
            @enderror

            <div class="flex items-center justify-between gap-3">
                <p class="text-sm text-gray-600">{{ __('Cada equipo define en qué estaciones existe y qué secciones (grupos del menú) usa. Los equipos se vinculan con Tickets: el equipo central y un subequipo por estación.') }}</p>
                @can('create', \App\Models\Equipo::class)
                    <a href="{{ route('equipos.create') }}" class="inline-flex min-h-[44px] shrink-0 items-center rounded-md bg-brand-green px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-brand-green-dark">{{ __('Nuevo equipo') }}</a>
                @endcan
            </div>

            <div class="overflow-x-auto rounded-xl border border-brand-green/10 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-brand-mist">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Equipo') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Clave') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Estaciones') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Secciones (grupos del menú)') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Cuentas') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($equipos as $equipo)
                            <tr class="hover:bg-brand-mist/40">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $equipo->nombre }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $equipo->clave }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $equipo->estaciones_count }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $equipo->menuGrupos->pluck('nombre')->join(', ') ?: '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $cuentas[$equipo->clave] ?? 0 }}</td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <div class="flex items-center justify-end gap-3">
                                        @can('update', $equipo)
                                            <a href="{{ route('equipos.edit', $equipo) }}" class="font-medium text-brand-teal hover:text-brand-green-dark">{{ __('Editar') }}</a>
                                            <form method="POST" action="{{ route('equipos.sincronizar', $equipo) }}">
                                                @csrf
                                                <button type="submit" class="font-medium text-brand-teal hover:text-brand-green-dark">{{ __('Sincronizar con Tickets') }}</button>
                                            </form>
                                        @endcan
                                        @can('delete', $equipo)
                                            <form method="POST" action="{{ route('equipos.destroy', $equipo) }}" onsubmit="return confirm('{{ __('¿Eliminar este equipo?') }}')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="font-medium text-red-600 hover:text-red-800">{{ __('Eliminar') }}</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-4 text-center text-sm text-gray-400">{{ __('Sin equipos.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
