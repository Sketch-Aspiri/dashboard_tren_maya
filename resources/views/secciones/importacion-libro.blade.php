<x-app-layout>
    <x-slot name="header">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $seccion->grupo->nombre }} · {{ $seccion->nombre }}</p>
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-green">{{ __('Vista previa de la carga: todas las hojas') }}</h2>
    </x-slot>

    <div class="py-6 sm:py-10">
        <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
            @include('secciones.partials.mensajes')

            <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5">
                <p class="text-sm text-gray-700">
                    {{ __('Documento:') }} <span class="font-semibold">{{ $importacion->archivo?->nombre_original ?? __('(sin archivo)') }}</span>
                    · {{ trans_choice('{1} :n hoja|[2,*] :n hojas', count($hojas), ['n' => count($hojas)]) }}
                </p>
                <p class="mt-2 text-xs text-gray-600">
                    {{ __('Cada hoja marcada se carga como una tabla propia dentro de esta sección, con el nombre de la hoja y las columnas de su renglón de títulos. Nada se guarda hasta que presiones "Aplicar".') }}
                    {{ __('Si ya existe una sección con el nombre de la hoja en este grupo, los datos se cargan en ella; si no, se crea.') }}
                </p>
                <p class="mt-2 text-xs text-gray-600">
                    {{ __('¿Los títulos no están en el renglón detectado?') }}
                    <a href="{{ route('secciones.importaciones.show', [$seccion, $importacion, 'tabla' => 0]) }}" class="font-semibold text-brand-teal underline">{{ __('Cargar una sola hoja y elegir el renglón') }}</a>
                </p>
            </div>

            <form method="POST" action="{{ route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]) }}" x-ref="formulario"
                  x-data="vistaPreviaLibro({ urlCalcular: @js(route('secciones.importaciones.libro.calcular', [$seccion, $importacion])), modo: @js($hojas[0]['modo'] ?? 'agregar') })"
                  x-init="calcular()"
                  @submit="if (! puedeAplicar()) { $event.preventDefault() } else { aplicando = true }"
                  @change="calcular()"
                  class="space-y-5">
                @csrf

                @foreach ($hojas as $indice => $hoja)
                    <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-sm shadow-brand-green/5" x-data="{ incluir: {{ $hoja['vacia'] ? 'false' : 'true' }} }">
                        <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-3">
                            <input type="hidden" name="hojas[{{ $indice }}][incluir]" value="0">
                            <label class="flex min-h-[44px] items-center gap-2">
                                <input type="checkbox" name="hojas[{{ $indice }}][incluir]" value="1" x-model="incluir" @checked(! $hoja['vacia'])
                                       class="rounded border-gray-300 text-brand-teal focus:ring-brand-teal">
                                <span class="font-heading text-base font-semibold text-brand-green">{{ $hoja['nombre'] }}</span>
                            </label>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $hoja['destino_nuevo'] ? 'bg-brand-mist text-brand-green' : 'bg-amber-100 text-amber-800' }}">
                                {{ $hoja['destino_nuevo'] ? __('Se crea una tabla nueva') : __('Se carga en la sección existente') }}
                            </span>
                            <span class="text-xs text-gray-600">{{ __(':n filas de datos', ['n' => number_format($hoja['total_filas'])]) }}</span>
                            @if ($hoja['vacia'])
                                <span class="text-xs font-medium text-gray-600">{{ __('(sin datos: se omite)') }}</span>
                            @endif
                        </div>

                        <input type="hidden" name="hojas[{{ $indice }}][fila_encabezado]" value="{{ $hoja['fila_encabezado'] }}">

                        <div x-show="incluir" class="overflow-x-auto">
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
                                    @foreach ($hoja['encabezados'] as $columna => $encabezado)
                                        <tr x-data="{ destino: @js($hoja['mapeo'][$columna] ?? 'nueva') }">
                                            <td class="px-4 py-2 font-medium text-gray-900">{{ $encabezado }}</td>
                                            <td class="px-4 py-2">
                                                <label class="sr-only" for="mapeo-{{ $indice }}-{{ $columna }}">{{ __('Destino de :col', ['col' => $encabezado]) }}</label>
                                                <select id="mapeo-{{ $indice }}-{{ $columna }}" name="hojas[{{ $indice }}][mapeo][{{ $columna }}]" x-model="destino"
                                                        class="min-h-[44px] w-full min-w-[12rem] rounded-md border-gray-300 text-sm shadow-sm">
                                                    <option value="nueva">{{ __('+ Columna nueva') }}</option>
                                                    <option value="ignorar">{{ __('No importar') }}</option>
                                                    @foreach ($hoja['columnas_destino'] as $existente)
                                                        <option value="{{ $existente->clave }}">{{ $existente->nombre }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="px-4 py-2">
                                                <span x-show="destino !== 'nueva'" class="text-xs text-gray-500">{{ __('— (usa el de la columna)') }}</span>
                                                <label class="sr-only" for="tipo-{{ $indice }}-{{ $columna }}">{{ __('Tipo de :col', ['col' => $encabezado]) }}</label>
                                                <select id="tipo-{{ $indice }}-{{ $columna }}" name="hojas[{{ $indice }}][tipos][{{ $columna }}]"
                                                        x-show="destino === 'nueva'" :disabled="destino !== 'nueva'"
                                                        class="min-h-[44px] rounded-md border-gray-300 text-sm shadow-sm">
                                                    @foreach ($tiposColumna as $tipo)
                                                        @continue($tipo === \App\Enums\TipoColumna::Lista)
                                                        <option value="{{ $tipo->value }}" @selected(($hoja['tipos'][$columna] ?? 'texto') === $tipo->value)>{{ $tipo->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="max-w-xs px-4 py-2 text-xs text-gray-600">
                                                {{ \Illuminate\Support\Str::limit(implode(' · ', array_slice(array_filter(array_map(fn ($fila) => \App\Services\Secciones\FotoService::nombreDe($fila[$columna] ?? null) !== null ? __('(foto)') : (string) ($fila[$columna] ?? ''), $hoja['muestra']), 'strlen'), 0, 3)), 90) ?: '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                            <div x-show="modo === 'actualizar'" x-cloak class="border-t border-gray-100 px-5 py-3">
                                <label for="llave-{{ $indice }}" class="block text-sm font-medium text-gray-700">{{ __('Columna llave de esta hoja (identifica cada registro)') }}</label>
                                <select id="llave-{{ $indice }}" name="hojas[{{ $indice }}][llave]" class="mt-1 block min-h-[44px] w-full max-w-md rounded-md border-gray-300 text-sm shadow-sm">
                                    <option value="">{{ __('— Elige una columna —') }}</option>
                                    @foreach ($hoja['encabezados'] as $columna => $encabezado)
                                        <option value="{{ $columna }}" @selected($hoja['llave'] === $columna)>{{ $encabezado }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                @endforeach

                <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5">
                    <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('¿Cómo se aplica? (a todas las hojas marcadas)') }}</h3>
                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                        @foreach ($modos as $modo)
                            @php $esReemplazo = $modo === \App\Enums\ModoImportacion::Reemplazar; @endphp
                            <label class="flex cursor-pointer gap-3 rounded-lg border p-3 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-teal"
                                   :class="modo === @js($modo->value) ? @js($esReemplazo ? 'border-red-400 bg-red-50' : 'border-brand-teal bg-brand-mist') : 'border-gray-200 hover:bg-gray-50'">
                                <input type="radio" name="modo" value="{{ $modo->value }}" x-model="modo" class="mt-1 text-brand-teal focus:ring-brand-teal">
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900">{{ $modo->label() }}</span>
                                    <span class="block text-xs text-gray-600">
                                        @switch($modo)
                                            @case(\App\Enums\ModoImportacion::Agregar) {{ __('Las filas de cada hoja se suman a las de su tabla.') }} @break
                                            @case(\App\Enums\ModoImportacion::Actualizar) {{ __('Se actualizan las filas que coinciden por la columna llave de cada hoja; las demás se agregan.') }} @break
                                            @case(\App\Enums\ModoImportacion::Reemplazar) <span class="font-medium text-red-800">{{ __('Borra los registros actuales de cada tabla destino. Se puede deshacer desde la pestaña Cargas.') }}</span> @break
                                        @endswitch
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-xl border border-brand-green/10 bg-white p-5 shadow-sm shadow-brand-green/5">
                    <h3 class="font-heading text-base font-semibold text-brand-green">{{ __('Lo que va a pasar') }}</h3>
                    <p x-show="calculando" x-cloak class="mt-2 text-sm text-gray-600">{{ __('Calculando…') }}</p>
                    <p x-show="error" x-cloak role="alert" class="mt-2 rounded-md bg-red-50 p-3 text-sm text-red-800" x-text="@js(__('No se pudo calcular:')) + ' ' + error"></p>

                    <template x-if="resumen && ! calculando && ! error">
                        <div class="mt-3 space-y-3" role="status">
                            <p class="text-sm text-gray-700">
                                <span class="font-semibold" x-text="resumen.nuevas"></span> {{ __('nuevas') }},
                                <span class="font-semibold" x-text="resumen.actualizadas"></span> {{ __('actualizadas') }},
                                <span class="font-semibold" x-text="resumen.sin_cambios"></span> {{ __('sin cambios') }},
                                <span class="font-semibold" :class="resumen.eliminadas > 0 ? 'text-red-700' : ''" x-text="resumen.eliminadas"></span> {{ __('se eliminan') }}.
                            </p>
                            <ul class="space-y-1 text-sm text-gray-700">
                                <template x-for="hoja in resumen.hojas" :key="hoja.tabla">
                                    <li>
                                        <span class="font-medium" x-text="hoja.nombre"></span>:
                                        <span x-show="hoja.omitida">{{ __('sin datos, se omite') }}</span>
                                        <span x-show="! hoja.omitida" x-text="(hoja.destino_nuevo ? @js(__('tabla nueva')) + ', ' : '') + hoja.nuevas + ' ' + @js(__('nuevas')) + ', ' + hoja.actualizadas + ' ' + @js(__('actualizadas')) + ', ' + hoja.eliminadas + ' ' + @js(__('se eliminan'))"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>
                </div>

                <div x-show="modo === 'reemplazar' && resumen && resumen.eliminadas > 0" x-cloak
                     class="space-y-3 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-900" role="alert">
                    <p class="font-semibold" x-text="@js(__('Se van a eliminar')) + ' ' + (resumen?.eliminadas ?? 0) + ' ' + @js(__('registros que hoy están en las tablas.'))"></p>
                    <label class="flex min-h-[44px] items-center gap-2">
                        <input type="checkbox" name="confirma_reemplazo" value="1" x-model="confirmaReemplazo" class="rounded border-red-400 text-red-700 focus:ring-red-500">
                        <span>{{ __('Entiendo que se eliminarán esos registros y quiero reemplazar las tablas.') }}</span>
                    </label>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                    <p class="text-xs text-gray-600 sm:me-auto">{{ __('Si eliges "Decidir después", la carga queda guardada en la pestaña Cargas.') }}</p>
                    <a href="{{ route('secciones.show', $seccion) }}" class="inline-flex min-h-[44px] items-center justify-center rounded-md px-4 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Decidir después') }}</a>
                    <button type="submit" :disabled="! puedeAplicar()"
                            :class="modo === 'reemplazar' ? 'bg-red-700 hover:bg-red-800 focus:ring-red-500' : 'bg-brand-green hover:bg-brand-green-dark focus:ring-brand-teal'"
                            class="inline-flex min-h-[44px] items-center justify-center rounded-md px-5 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                        <span x-text="aplicando ? @js(__('Aplicando…')) : @js(__('Aplicar a todas las hojas marcadas'))">{{ __('Aplicar a todas las hojas marcadas') }}</span>
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
