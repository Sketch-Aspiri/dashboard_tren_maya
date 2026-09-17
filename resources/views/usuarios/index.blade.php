<x-app-layout>
    <x-slot name="header">
        <h2 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Gestión de usuarios') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md border border-brand-teal/20 bg-brand-mist p-4 text-sm text-brand-green">
                    {{ session('status') }}
                </div>
            @endif

            <div class="flex justify-end">
                @can('create', \App\Models\User::class)
                    <a href="{{ route('usuarios.create') }}"
                       class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-transparent bg-brand-green px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-brand-green-dark focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2">
                        {{ __('Nueva cuenta') }}
                    </a>
                @endcan
            </div>

            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <!-- Desktop / tablet table (>= sm) -->
                <div class="hidden overflow-x-auto sm:block">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-brand-mist">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Nombre') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Correo') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Rol') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Estación') }}</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @forelse ($users as $u)
                                <tr class="hover:bg-brand-mist/40">
                                    <td class="px-6 py-4 text-sm text-gray-900">{{ $u->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $u->email }}</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="inline-flex rounded-full bg-brand-mist px-2 py-1 text-xs font-semibold text-brand-green">
                                            {{ $u->roles->pluck('name')->join(', ') ?: __('Sin rol') }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $u->estacion?->nombre ?? '—' }}</td>
                                    <td class="px-6 py-4 text-right text-sm">
                                        <div class="flex items-center justify-end gap-3">
                                            @can('update', $u)
                                                <a href="{{ route('usuarios.edit', $u) }}" class="font-medium text-brand-teal hover:text-brand-green-dark">{{ __('Editar') }}</a>
                                            @endcan
                                            @can('delete', $u)
                                                <form method="POST" action="{{ route('usuarios.destroy', $u) }}"
                                                      onsubmit="return confirm('{{ __('¿Eliminar esta cuenta? Esta acción no se puede deshacer.') }}')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="font-medium text-red-600 hover:text-red-800">{{ __('Eliminar') }}</button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-400">{{ __('Sin cuentas registradas.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Mobile stacked cards (< sm) -->
                <div class="divide-y divide-gray-200 sm:hidden">
                    @forelse ($users as $u)
                        <div class="px-4 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-900">{{ $u->name }}</p>
                                    <p class="mt-0.5 truncate text-xs text-gray-500">{{ $u->email }}</p>
                                </div>
                                <span class="inline-flex shrink-0 rounded-full bg-brand-mist px-2 py-1 text-xs font-semibold text-brand-green">
                                    {{ $u->roles->pluck('name')->join(', ') ?: __('Sin rol') }}
                                </span>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-gray-500">
                                <div class="col-span-2">
                                    <dt class="text-gray-400">{{ __('Estación') }}</dt>
                                    <dd class="text-gray-700">{{ $u->estacion?->nombre ?? '—' }}</dd>
                                </div>
                            </dl>
                            <div class="mt-3 flex items-center gap-4">
                                @can('update', $u)
                                    <a href="{{ route('usuarios.edit', $u) }}" class="text-sm font-medium text-brand-teal hover:text-brand-green-dark">{{ __('Editar') }}</a>
                                @endcan
                                @can('delete', $u)
                                    <form method="POST" action="{{ route('usuarios.destroy', $u) }}"
                                          onsubmit="return confirm('{{ __('¿Eliminar esta cuenta? Esta acción no se puede deshacer.') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-800">{{ __('Eliminar') }}</button>
                                    </form>
                                @endcan
                            </div>
                        </div>
                    @empty
                        <p class="px-4 py-6 text-center text-sm text-gray-400">{{ __('Sin cuentas registradas.') }}</p>
                    @endforelse
                </div>

                @if ($users->hasPages())
                    <div class="border-t border-gray-200 bg-brand-mist/50 px-4 py-3 sm:px-6">
                        {{ $users->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
