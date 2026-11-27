<x-app-layout>
    <x-slot name="header">
        <h1 class="font-heading text-xl font-semibold text-brand-green leading-tight">
            {{ __('Gestión de usuarios') }}
        </h1>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div data-aviso class="rounded-md border border-brand-teal/20 bg-brand-mist p-4 text-sm text-brand-green">
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

            @php
                $base = request()->except('page');
                $orden = fn (string $col) => array_merge($base, ['orden' => $col, 'dir' => ($filtros['orden'] === $col && $filtros['dir'] === 'asc') ? 'desc' : 'asc']);
                $flecha = fn (string $col) => $filtros['orden'] === $col ? ($filtros['dir'] === 'asc' ? '▲' : '▼') : '';
                $sentido = fn (string $col) => $filtros['orden'] === $col ? ($filtros['dir'] === 'asc' ? 'ascending' : 'descending') : null;
                $hayFiltros = $filtros['rol'] !== '' || $filtros['estacion'] !== '' || $filtros['area'] !== '' || $filtros['q'] !== '';
            @endphp

            <form method="GET" action="{{ route('usuarios.index') }}" class="grid gap-3 rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
                <input type="hidden" name="orden" value="{{ $filtros['orden'] }}">
                <input type="hidden" name="dir" value="{{ $filtros['dir'] }}">
                <div class="lg:col-span-2">
                    <label for="f-q" class="block text-xs font-medium text-gray-500">{{ __('Buscar') }}</label>
                    <input id="f-q" type="search" name="q" value="{{ $filtros['q'] }}" placeholder="{{ __('Nombre o correo') }}"
                           class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label for="f-rol" class="block text-xs font-medium text-gray-500">{{ __('Tipo de cuenta') }}</label>
                    <select id="f-rol" name="rol" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">{{ __('Todos') }}</option>
                        @foreach ($roles as $r)
                            <option value="{{ $r }}" @selected($filtros['rol'] === $r)>{{ $r }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-estacion" class="block text-xs font-medium text-gray-500">{{ __('Estación') }}</label>
                    <select id="f-estacion" name="estacion" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">{{ __('Todas') }}</option>
                        <option value="sin" @selected($filtros['estacion'] === 'sin')>{{ __('Sin estación') }}</option>
                        @foreach ($estaciones as $e)
                            <option value="{{ $e->id }}" @selected($filtros['estacion'] === (string) $e->id)>{{ $e->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-area" class="block text-xs font-medium text-gray-500">{{ __('Área') }}</label>
                    <select id="f-area" name="area" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">{{ __('Todas') }}</option>
                        <option value="sin" @selected($filtros['area'] === 'sin')>{{ __('Sin área') }}</option>
                        @foreach (\App\Support\Areas::nombres() as $clave => $nombreArea)
                            <option value="{{ $clave }}" @selected($filtros['area'] === $clave)>{{ $nombreArea }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-3 sm:col-span-2 lg:col-span-5">
                    <button type="submit" class="inline-flex min-h-[40px] items-center rounded-md bg-brand-green px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-brand-green-dark">{{ __('Filtrar') }}</button>
                    @if ($hayFiltros)
                        <a href="{{ route('usuarios.index', ['orden' => $filtros['orden'], 'dir' => $filtros['dir']]) }}" class="text-sm font-medium text-brand-teal hover:text-brand-green-dark">{{ __('Limpiar filtros') }}</a>
                    @endif
                    <span class="ml-auto text-xs text-gray-500">{{ trans_choice(':count cuenta|:count cuentas', $users->total()) }}</span>
                </div>
            </form>
            <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                <!-- Desktop / tablet table (>= sm) -->
                <div class="hidden overflow-x-auto sm:block">
                    <table class="min-w-full divide-y divide-gray-200">
                        <caption class="sr-only">{{ __('Cuentas') }}</caption>
                        <thead class="bg-brand-mist">
                            <tr>
                                <th scope="col" @if ($sentido('nombre')) aria-sort="{{ $sentido('nombre') }}" @endif class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green"><a href="{{ route('usuarios.index', $orden('nombre')) }}" class="hover:underline">{{ __('Nombre') }} <span aria-hidden="true">{{ $flecha('nombre') }}</span></a></th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green">{{ __('Correo') }}</th>
                                <th scope="col" @if ($sentido('rol')) aria-sort="{{ $sentido('rol') }}" @endif class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green"><a href="{{ route('usuarios.index', $orden('rol')) }}" class="hover:underline">{{ __('Rol') }} <span aria-hidden="true">{{ $flecha('rol') }}</span></a></th>
                                <th scope="col" @if ($sentido('estacion')) aria-sort="{{ $sentido('estacion') }}" @endif class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green"><a href="{{ route('usuarios.index', $orden('estacion')) }}" class="hover:underline">{{ __('Estación') }} <span aria-hidden="true">{{ $flecha('estacion') }}</span></a></th>
                                <th scope="col" @if ($sentido('area')) aria-sort="{{ $sentido('area') }}" @endif class="px-6 py-3 text-left text-xs font-medium uppercase text-brand-green"><a href="{{ route('usuarios.index', $orden('area')) }}" class="hover:underline">{{ __('Área') }} <span aria-hidden="true">{{ $flecha('area') }}</span></a></th>
                                <th scope="col" class="px-6 py-3"><span class="sr-only">{{ __('Acciones') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @forelse ($users as $u)
                                <tr class="hover:bg-brand-mist/40">
                                    <th scope="row" class="px-6 py-4 text-left text-sm font-normal text-gray-900">{{ $u->name }}</th>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $u->email }}</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="inline-flex rounded-full bg-brand-mist px-2 py-1 text-xs font-semibold text-brand-green">
                                            {{ $u->roles->pluck('name')->join(', ') ?: __('Sin rol') }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $u->estacion?->nombre ?? '—' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ collect($u->areas())->map(fn ($a) => \App\Support\Areas::nombres()[$a] ?? $a)->join(', ') ?: '—' }}</td>
                                    <td class="px-6 py-4 text-right text-sm">
                                        <div class="flex items-center justify-end gap-3">
                                            @can('update', $u)
                                                <a href="{{ route('usuarios.edit', $u) }}" class="font-medium text-brand-teal hover:text-brand-green-dark">{{ __('Editar') }}<span class="sr-only"> {{ $u->name }}</span></a>
                                            @endcan
                                            @can('delete', $u)
                                                <form method="POST" action="{{ route('usuarios.destroy', $u) }}"
                                                      onsubmit="return confirm('{{ __('¿Eliminar esta cuenta? Esta acción no se puede deshacer.') }}')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="font-medium text-red-600 hover:text-red-800">{{ __('Eliminar') }}<span class="sr-only"> {{ $u->name }}</span></button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-400">{{ __('Sin cuentas registradas.') }}</td>
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
                                    <p class="break-words text-sm font-semibold text-gray-900">{{ $u->name }}</p>
                                    <p class="mt-0.5 break-all text-xs text-gray-500">{{ $u->email }}</p>
                                </div>
                                <span class="inline-flex shrink-0 rounded-full bg-brand-mist px-2 py-1 text-xs font-semibold text-brand-green">
                                    {{ $u->roles->pluck('name')->join(', ') ?: __('Sin rol') }}
                                </span>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-gray-500">
                                <div>
                                    <dt class="text-gray-400">{{ __('Estación') }}</dt>
                                    <dd class="text-gray-700">{{ $u->estacion?->nombre ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-400">{{ __('Área') }}</dt>
                                    <dd class="text-gray-700">{{ collect($u->areas())->map(fn ($a) => \App\Support\Areas::nombres()[$a] ?? $a)->join(', ') ?: '—' }}</dd>
                                </div>
                            </dl>
                            <div class="mt-3 flex items-center gap-4">
                                @can('update', $u)
                                    <a href="{{ route('usuarios.edit', $u) }}" class="text-sm font-medium text-brand-teal hover:text-brand-green-dark">{{ __('Editar') }}<span class="sr-only"> {{ $u->name }}</span></a>
                                @endcan
                                @can('delete', $u)
                                    <form method="POST" action="{{ route('usuarios.destroy', $u) }}"
                                          onsubmit="return confirm('{{ __('¿Eliminar esta cuenta? Esta acción no se puede deshacer.') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-800">{{ __('Eliminar') }}<span class="sr-only"> {{ $u->name }}</span></button>
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
