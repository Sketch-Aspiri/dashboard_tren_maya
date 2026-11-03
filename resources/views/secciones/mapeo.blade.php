<x-app-layout>
    <x-slot name="header">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $seccion->grupo->nombre }} · {{ $seccion->nombre }}</p>
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green">{{ __('Editar mapeo de tablas') }}: {{ $origen->nombre }}</h2>
    </x-slot>

    <div class="py-6 sm:py-10">
        <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
            @include('secciones.partials.mensajes')

            <p class="text-sm text-gray-700">
                {{ __('El mapeo dice en qué fila están los títulos de cada hoja y qué columna identifica cada registro (llave). Al guardar, la tabla se vuelve a calcular desde el Excel de la nube.') }}
            </p>

            @forelse ($hojas as $hoja)
                @php
                    $mapeo = $hoja['mapeo'];
                    $filas = $hoja['primeras_filas'];
                @endphp
                <section class="space-y-3 rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5" aria-labelledby="hoja-{{ $mapeo->id }}">
                    <h3 id="hoja-{{ $mapeo->id }}" class="font-heading text-base font-semibold text-brand-green">{{ $hoja['tabla']->nombre }}
                        <span class="text-xs font-normal text-gray-500">({{ __('hoja :hoja', ['hoja' => $mapeo->hoja]) }})</span>
                    </h3>

                    @if ($mapeo->confirmado_en === null)
                        <p class="rounded-md bg-amber-50 p-2 text-xs text-amber-900">{{ __('Tabla nueva creada automáticamente: revisar mapeo.') }}</p>
                    @endif

                    @if ($mapeo->estaRetenido())
                        <x-retenido-mapeo :mapeo="$mapeo" :origen="$origen" />
                    @endif

                    @if (! $hoja['legible'])
                        <p class="text-sm text-red-800">{{ __('El documento no se puede leer ahora: no se puede editar este mapeo.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-xs">
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($filas as $numero => $fila)
                                        <tr @class(['bg-brand-mist font-semibold' => $numero === $mapeo->fila_encabezado])>
                                            <th scope="row" class="px-2 py-1 text-left text-gray-500">{{ $numero + 1 }}</th>
                                            @foreach ($fila as $celda)
                                                <td class="max-w-[12rem] truncate px-2 py-1">{{ is_scalar($celda) ? $celda : '' }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @empty
                <p class="rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600">{{ __('Este origen aún no tiene tablas mapeadas.') }}</p>
            @endforelse

            @if (collect($hojas)->contains(fn ($h) => $h['legible']))
                <form method="POST" action="{{ route('secciones.origenes.mapeo.update', [$seccion, $origen]) }}" class="space-y-4 rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5">
                    @csrf
                    @foreach ($hojas as $hoja)
                        @continue(! $hoja['legible'])
                        @php $mapeo = $hoja['mapeo']; @endphp
                        <fieldset class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <legend class="mb-2 font-heading text-sm font-semibold text-brand-green">{{ $hoja['tabla']->nombre }}</legend>
                            <div>
                                <label for="fila-{{ $mapeo->id }}" class="block text-sm font-medium text-gray-700">{{ __('Fila de encabezados') }}</label>
                                <select id="fila-{{ $mapeo->id }}" name="mapeos[{{ $mapeo->id }}][fila_encabezado]" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    @foreach (range(0, max(0, count($hoja['primeras_filas']) - 1)) as $numero)
                                        <option value="{{ $numero }}" @selected($numero === $mapeo->fila_encabezado)>{{ __('Fila :n', ['n' => $numero + 1]) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="llave-{{ $mapeo->id }}" class="block text-sm font-medium text-gray-700">{{ __('Columna llave') }}</label>
                                <select id="llave-{{ $mapeo->id }}" name="mapeos[{{ $mapeo->id }}][llave_clave]" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    <option value="">{{ __('Sin llave (reemplazar todo si cambia)') }}</option>
                                    @foreach ($hoja['columnas'] as $columna)
                                        <option value="{{ $columna->clave }}" @selected($columna->clave === $mapeo->llave_clave)>{{ $columna->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </fieldset>
                    @endforeach
                    <p class="text-xs text-gray-600">{{ __('Cambiar la fila de encabezados puede crear columnas nuevas; las anteriores se conservan.') }}</p>
                    <div class="flex justify-end gap-2">
                        <a href="{{ route('secciones.show', $seccion) }}" class="inline-flex min-h-[44px] items-center rounded-md px-4 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Volver a la sección') }}</a>
                        <button type="submit" class="min-h-[44px] rounded-md bg-brand-green px-5 text-sm font-semibold text-white hover:bg-brand-green-dark focus:outline-none focus:ring-2 focus:ring-brand-teal focus:ring-offset-2">{{ __('Guardar mapeo') }}</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
