@if ($columnas->isEmpty())
    <div class="rounded-xl border border-dashed border-brand-green/25 bg-white p-8 text-center shadow-sm shadow-brand-green/5">
        <p class="font-heading text-base font-semibold text-brand-green">{{ __('Esta sección aún no tiene tabla') }}</p>
        <p class="mx-auto mt-2 max-w-xl text-sm text-gray-600">
            {{ __('Arrastra arriba un Excel, CSV, Word o PDF que contenga una tabla: el sistema crea las columnas automáticamente. O define las columnas a mano.') }}
        </p>
        @if ($puedeEditarSeccion)
            <a href="{{ route('secciones.columnas.index', $seccion) }}"
               class="mt-4 inline-flex min-h-[44px] items-center rounded-md border border-brand-green/20 px-4 text-sm font-medium text-brand-green hover:bg-brand-mist">
                {{ __('Definir columnas a mano') }}
            </a>
        @endif
    </div>
@else
    <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
        {{-- Escritorio / tableta --}}
        <div class="hidden overflow-x-auto sm:block">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-brand-mist">
                    <tr>
                        @foreach ($columnas as $columna)
                            <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase text-brand-green"
                                @if ($filtros['sort'] === $columna->clave) aria-sort="{{ $filtros['direction'] === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                <a href="{{ $enlaceOrden($columna->clave) }}" class="inline-flex items-center gap-1 hover:underline">
                                    {{ $columna->nombre }}
                                    @if ($filtros['sort'] === $columna->clave)
                                        <span aria-hidden="true">{{ $filtros['direction'] === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </a>
                            </th>
                        @endforeach
                        <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Acciones') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($filas as $fila)
                        <tr class="align-top hover:bg-brand-mist/40">
                            @foreach ($columnas as $columna)
                                @php $texto = $normalizador->paraMostrar($fila->datos[$columna->clave] ?? null, $columna->tipo); @endphp
                                <td class="max-w-xs px-4 py-3 text-gray-800" title="{{ \Illuminate\Support\Str::length($texto) > 60 ? $texto : '' }}">
                                    {{ \Illuminate\Support\Str::limit($texto, 60) ?: '—' }}
                                </td>
                            @endforeach
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                @include('secciones.partials.acciones-fila')
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $columnas->count() + 1 }}" class="px-4 py-8 text-center text-sm text-gray-500">
                                {{ $filtros['q'] ? __('Ningún registro coincide con la búsqueda.') : __('La tabla está vacía. Agrega un registro o arrastra un documento.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Celular: tarjetas --}}
        <div class="divide-y divide-gray-100 sm:hidden">
            @forelse ($filas as $fila)
                <div class="px-4 py-4">
                    <dl class="grid grid-cols-1 gap-1 text-sm">
                        @foreach ($columnas->take(6) as $columna)
                            <div class="flex gap-2">
                                <dt class="w-1/3 shrink-0 text-xs text-gray-500">{{ $columna->nombre }}</dt>
                                <dd class="min-w-0 break-words text-gray-800">{{ $normalizador->paraMostrar($fila->datos[$columna->clave] ?? null, $columna->tipo) ?: '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <div class="mt-2 flex flex-wrap justify-end gap-1">
                        @include('secciones.partials.acciones-fila')
                    </div>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-gray-500">{{ __('Sin registros.') }}</p>
            @endforelse
        </div>

        <div class="flex flex-col gap-3 border-t border-gray-200 bg-brand-mist/50 px-4 py-3 text-sm text-gray-600 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" action="{{ route('secciones.show', $seccion) }}" class="flex items-center gap-2">
                <input type="hidden" name="q" value="{{ $filtros['q'] }}">
                <input type="hidden" name="sort" value="{{ $filtros['sort'] }}">
                <input type="hidden" name="direction" value="{{ $filtros['direction'] }}">
                <label for="por-pagina">{{ __('Mostrar') }}</label>
                <select id="por-pagina" name="per_page" onchange="this.form.submit()" class="min-h-[36px] rounded-md border-gray-300 py-1 text-sm">
                    @foreach (config('secciones.filas_por_pagina') as $opcion)
                        <option value="{{ $opcion }}" @selected($filtros['per_page'] === $opcion)>{{ $opcion }}</option>
                    @endforeach
                </select>
            </form>
            {{ $filas->onEachSide(1)->links() }}
        </div>
    </div>
@endif
