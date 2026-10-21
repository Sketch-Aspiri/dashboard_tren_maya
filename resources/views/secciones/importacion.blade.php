@php
    $columnasExistentes = $seccion->columnas->map(fn ($columna) => ['clave' => $columna->clave, 'nombre' => $columna->nombre])->values();
@endphp

<x-app-layout>
    <x-slot name="header">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $seccion->grupo->nombre }} · {{ $seccion->nombre }}</p>
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green">{{ __('Vista previa de la carga') }}</h2>
    </x-slot>

    <div class="py-6 sm:py-10">
        <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
            @include('secciones.partials.mensajes')

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5">
                <p class="text-sm text-gray-700">
                    {{ __('Documento:') }} <span class="font-semibold">{{ $importacion->archivo?->nombre_original ?? __('(sin archivo)') }}</span>
                </p>
                @if ($importacion->origen === \App\Models\SeccionImportacion::ORIGEN_IA)
                    <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="note">
                        {{ __('Estos datos los leyó una inteligencia artificial y pueden tener errores. Compáralos con el documento original antes de aplicar.') }}
                        @if ($importacion->archivo)
                            <a href="{{ route('secciones.archivos.show', [$seccion, $importacion->archivo, 'ver' => $importacion->archivo->admiteVistaPrevia() ? 1 : null]) }}"
                               target="_blank" rel="noopener" class="font-semibold underline">{{ __('Ver documento original') }}</a>
                        @endif
                    </div>
                @endif
                <p class="mt-2 text-xs text-gray-600">{{ __('Nada se guarda en la tabla hasta que presiones "Aplicar". Revisa abajo qué columnas se importan y cómo.') }}</p>

                @if (count($importacion->tablas) >= 2)
                    <p class="mt-2 text-xs text-gray-600">
                        {{ __('Este documento tiene varias hojas.') }}
                        <a href="{{ route('secciones.importaciones.show', [$seccion, $importacion, 'libro' => 1]) }}" class="font-semibold text-brand-teal underline">{{ __('Cargar todas las hojas de una vez') }}</a>
                    </p>
                @endif

                {{-- 1. Tabla del documento y fila de encabezados (recarga la página) --}}
                <form method="GET" action="{{ route('secciones.importaciones.show', [$seccion, $importacion]) }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label for="tabla" class="block text-sm font-medium text-gray-700">{{ __('Tabla / hoja del documento') }}</label>
                        <select id="tabla" name="tabla" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm">
                            @foreach ($importacion->tablas as $indice => $tabla)
                                <option value="{{ $indice }}" @selected($propuesta['tabla'] === $indice)>
                                    {{ $tabla['nombre'] }} ({{ count($tabla['filas']) }} {{ __('renglones') }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="fila_encabezado" class="block text-sm font-medium text-gray-700">{{ __('Renglón con los títulos de columna') }}</label>
                        <select id="fila_encabezado" name="fila_encabezado" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm">
                            @foreach ($propuesta['primeras_filas'] as $indice => $fila)
                                <option value="{{ $indice }}" @selected($propuesta['fila_encabezado'] === $indice)>
                                    {{ $indice + 1 }}: {{ \Illuminate\Support\Str::limit(implode(' | ', array_filter(array_map('strval', $fila), 'strlen')), 70) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex flex-col justify-end gap-2">
                        <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-md border border-brand-green/20 bg-white px-4 text-sm font-medium text-brand-green hover:bg-brand-mist focus:outline-none focus:ring-2 focus:ring-brand-teal">
                            {{ __('Actualizar vista') }}
                        </button>
                        <p class="text-xs text-gray-600">{{ __(':n filas de datos detectadas. Si cambias la hoja o el renglón de títulos, el mapeo se vuelve a calcular.', ['n' => number_format($propuesta['total_filas'])]) }}</p>
                    </div>
                </form>
            </div>

            {{-- 2. Mapeo, modo y cálculo de cambios --}}
            <form method="POST" action="{{ route('secciones.importaciones.aplicar', [$seccion, $importacion]) }}"
                  x-data="vistaPreviaImportacion({
                      urlCalcular: @js(route('secciones.importaciones.calcular', [$seccion, $importacion])),
                      tabla: {{ $propuesta['tabla'] }},
                      filaEncabezado: {{ $propuesta['fila_encabezado'] }},
                      mapeo: @js($propuesta['mapeo']),
                      tipos: @js($propuesta['tipos']),
                      modo: @js($propuesta['modo']),
                      llave: @js($propuesta['llave']),
                      encabezados: @js($propuesta['encabezados']),
                      columnas: @js($columnasExistentes),
                  })"
                  x-init="calcular()"
                  @submit="if (! puedeAplicar()) { $event.preventDefault() } else { aplicando = true }"
                  class="space-y-5">
                @csrf
                <input type="hidden" name="tabla" :value="tabla">
                <input type="hidden" name="fila_encabezado" :value="filaEncabezado">

                <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('Columnas') }}</h3>
                        <p class="text-xs text-gray-500">{{ __('Para cada columna del documento elige si va a una columna existente, si se crea una nueva o si se ignora.') }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-brand-mist">
                                <tr>
                                    <th scope="col" class="px-4 py-2 text-left text-xs font-semibold uppercase text-brand-green">{{ __('En el documento') }}</th>
                                    <th scope="col" class="px-4 py-2 text-left text-xs font-semibold uppercase text-brand-green">{{ __('Se guarda en') }}</th>
                                    <th scope="col" class="px-4 py-2 text-left text-xs font-semibold uppercase text-brand-green">{{ __('Tipo (columna nueva)') }}</th>
                                    <th scope="col" class="px-4 py-2 text-left text-xs font-semibold uppercase text-brand-green">{{ __('Ejemplos') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($propuesta['encabezados'] as $indice => $encabezado)
                                    <tr>
                                        <td class="px-4 py-2 font-medium text-gray-900">{{ $encabezado }}</td>
                                        <td class="px-4 py-2">
                                            <label class="sr-only" for="mapeo-{{ $indice }}">{{ __('Destino de :col', ['col' => $encabezado]) }}</label>
                                            <select id="mapeo-{{ $indice }}" name="mapeo[{{ $indice }}]" x-model="mapeo[{{ $indice }}]" @change="calcular()"
                                                    class="min-h-[44px] w-full min-w-[12rem] rounded-md border-gray-300 text-sm shadow-sm">
                                                <option value="nueva">{{ __('+ Columna nueva') }}</option>
                                                <option value="ignorar">{{ __('No importar') }}</option>
                                                @foreach ($seccion->columnas as $columna)
                                                    <option value="{{ $columna->clave }}">{{ $columna->nombre }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-4 py-2">
                                            <label class="sr-only" for="tipo-{{ $indice }}">{{ __('Tipo de :col', ['col' => $encabezado]) }}</label>
                                            <span x-show="mapeo[{{ $indice }}] !== 'nueva'" class="text-xs text-gray-500">{{ __('— (usa el de la columna)') }}</span>
                                            <select id="tipo-{{ $indice }}" name="tipos[{{ $indice }}]" x-model="tipos[{{ $indice }}]" @change="calcular()"
                                                    x-show="mapeo[{{ $indice }}] === 'nueva'" :disabled="mapeo[{{ $indice }}] !== 'nueva'"
                                                    class="min-h-[44px] rounded-md border-gray-300 text-sm shadow-sm">
                                                @foreach ($tiposColumna as $tipo)
                                                    @continue($tipo === \App\Enums\TipoColumna::Lista)
                                                    <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="max-w-xs px-4 py-2 text-xs text-gray-600">
                                            {{ \Illuminate\Support\Str::limit(implode(' · ', array_slice(array_filter(array_map(fn ($fila) => (string) ($fila[$indice] ?? ''), $propuesta['muestra']), 'strlen'), 0, 3)), 90) ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5">
                    <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('¿Cómo se aplica?') }}</h3>
                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                        @foreach ($modos as $modo)
                            @php $esReemplazo = $modo === \App\Enums\ModoImportacion::Reemplazar; @endphp
                            <label class="flex cursor-pointer gap-3 rounded-lg border p-3 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-teal"
                                   :class="modo === @js($modo->value) ? @js($esReemplazo ? 'border-red-400 bg-red-50' : 'border-brand-teal bg-brand-mist') : 'border-gray-200 hover:bg-gray-50'">
                                <input type="radio" name="modo" value="{{ $modo->value }}" x-model="modo" @change="calcular()" class="mt-1 text-brand-teal focus:ring-brand-teal">
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900">{{ $modo->label() }}</span>
                                    <span class="block text-xs text-gray-600">
                                        @switch($modo)
                                            @case(\App\Enums\ModoImportacion::Agregar) {{ __('Todas las filas del documento se suman a las que ya están.') }} @break
                                            @case(\App\Enums\ModoImportacion::Actualizar) {{ __('Las filas que coinciden por la columna llave se actualizan; las demás se agregan. No se borra nada.') }} @break
                                            @case(\App\Enums\ModoImportacion::Reemplazar) <span class="font-medium text-red-800">{{ __('Borra los registros actuales y deja solo los del documento. Se puede deshacer desde la pestaña Cargas.') }}</span> @break
                                        @endswitch
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div x-show="modo === 'actualizar'" x-cloak class="mt-4 max-w-md">
                        <label for="llave" class="block text-sm font-medium text-gray-700">{{ __('Columna llave (identifica cada registro, p. ej. No. de empleado o folio)') }}</label>
                        <select id="llave" name="llave" x-model="llave" @change="calcular()" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm">
                            <option value="">{{ __('— Elige una columna —') }}</option>
                            <template x-for="(encabezado, indice) in encabezados" :key="indice">
                                <option :value="indice" x-text="encabezado" :disabled="mapeo[indice] === 'ignorar'" :selected="String(llave) === String(indice)"></option>
                            </template>
                        </select>
                    </div>
                </div>

                {{-- 3. Resultado calculado por el servidor --}}
                <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5">
                    <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('Lo que va a pasar') }}</h3>
                    <p class="sr-only" role="status" x-text="anuncio()"></p>
                    <p x-show="calculando" x-cloak class="mt-2 text-sm text-gray-600">{{ __('Calculando…') }}</p>
                    <p x-show="error" x-cloak role="alert" class="mt-2 rounded-md bg-red-50 p-3 text-sm text-red-800" x-text="'{{ __('No se pudo calcular:') }} ' + error"></p>

                    <template x-if="resumen && ! calculando && ! error">
                        <div class="mt-3 space-y-3">
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div class="rounded-lg bg-brand-mist p-3"><p class="text-xs text-gray-600">{{ __('Nuevas') }}</p><p class="font-heading text-2xl font-bold text-brand-green" x-text="resumen.nuevas"></p></div>
                                <div class="rounded-lg bg-brand-mist p-3"><p class="text-xs text-gray-600">{{ __('Actualizadas') }}</p><p class="font-heading text-2xl font-bold text-brand-green" x-text="resumen.actualizadas"></p></div>
                                <div class="rounded-lg bg-brand-mist p-3"><p class="text-xs text-gray-600">{{ __('Sin cambios') }}</p><p class="font-heading text-2xl font-bold text-brand-green" x-text="resumen.sin_cambios"></p></div>
                                <div class="rounded-lg p-3" :class="resumen.eliminadas > 0 ? 'bg-red-50' : 'bg-brand-mist'"><p class="text-xs text-gray-600">{{ __('Se eliminan') }}</p><p class="font-heading text-2xl font-bold" :class="resumen.eliminadas > 0 ? 'text-red-700' : 'text-brand-green'" x-text="resumen.eliminadas"></p></div>
                            </div>
                            <p x-show="modo === 'actualizar' && resumen.no_vienen > 0" class="text-sm text-gray-600"
                               x-text="`${resumen.no_vienen} {{ __('registros actuales no vienen en el documento; se conservan sin cambios.') }}`"></p>
                            <p x-show="resumen.columnas_nuevas.length > 0" class="text-sm text-gray-700">
                                {{ __('Columnas nuevas:') }} <span class="font-medium" x-text="resumen.columnas_nuevas.join(', ')"></span>
                            </p>
                            <div x-show="Object.keys(resumen.muestra_actualizadas).length > 0">
                                <p class="text-sm font-medium text-gray-700">{{ __('Ejemplos de cambios:') }}</p>
                                <ul class="mt-1 space-y-1 text-xs text-gray-600">
                                    <template x-for="(cambios, id) in resumen.muestra_actualizadas" :key="id">
                                        <li>
                                            <template x-for="(cambio, clave) in cambios" :key="clave">
                                                <span class="me-3"><span class="font-medium" x-text="nombreColumna(clave)"></span>: <span class="line-through" x-text="cambio.antes ?? '—'"></span> → <span class="text-brand-green" x-text="cambio.despues ?? '—'"></span></span>
                                            </template>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                    </template>
                </div>

                <div x-show="modo === 'reemplazar' && resumen && resumen.eliminadas > 0" x-cloak
                     class="space-y-3 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-900" role="alert">
                    <p class="font-semibold" x-text="`{{ __('Se van a eliminar') }} ${resumen?.eliminadas ?? 0} {{ __('registros que hoy están en la tabla.') }}`"></p>
                    <label class="flex min-h-[44px] items-center gap-2">
                        <input type="checkbox" name="confirma_reemplazo" value="1" x-model="confirmaReemplazo" class="rounded border-red-400 text-red-700 focus:ring-red-500">
                        <span>{{ __('Entiendo que se eliminarán esos registros y quiero reemplazar la tabla.') }}</span>
                    </label>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                    <p class="text-xs text-gray-600 sm:me-auto">{{ __('Si eliges "Decidir después", la carga queda guardada en la pestaña Cargas.') }}</p>
                    <a href="{{ route('secciones.show', $seccion) }}" class="inline-flex min-h-[44px] items-center justify-center rounded-md px-4 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Decidir después') }}</a>
                    <button type="submit" :disabled="! puedeAplicar()"
                            :class="modo === 'reemplazar' ? 'bg-red-700 hover:bg-red-800 focus:ring-red-500' : 'bg-brand-green hover:bg-brand-green-dark focus:ring-brand-teal'"
                            class="inline-flex min-h-[44px] items-center justify-center rounded-md px-5 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                        <span x-text="textoBoton()">{{ __('Aplicar a la tabla') }}</span>
                    </button>
                </div>
            </form>

            <div class="flex justify-start border-t border-gray-200 pt-4">
                <x-confirmar-eliminar :action="route('secciones.importaciones.destroy', [$seccion, $importacion])"
                                      :etiqueta="__('Descartar carga')" :confirmar="__('Sí, descartar')"
                                      :pregunta="__('¿Descartar? El documento seguirá guardado en Documentos.')" />
            </div>
        </div>
    </div>
</x-app-layout>
